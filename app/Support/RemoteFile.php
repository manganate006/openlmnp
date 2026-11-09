<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Récupération d'un fichier fourni par un client MCP.
 *
 * Trois sources mutuellement exclusives : contenu base64, chemin absolu sur le
 * serveur (sous MCP_FILE_PATH_PREFIX), ou URL http/https.
 *
 * ⚠️ Le cas de l'URL est le point sensible : c'est NOTRE serveur qui émet la
 * requête. Sans contrôle, un client malveillant lui ferait lire des services
 * internes (169.254.169.254 et ses jetons d'instance, une base sur localhost,
 * un routeur du réseau privé). D'où la résolution DNS préalable, le refus de
 * toute IP privée / locale / réservée, et le refus des redirections — une URL
 * publique pouvant renvoyer un 3xx vers une adresse interne.
 *
 * Cette classe est la source unique de ces contrôles : ils sont partagés par
 * `attach_document` et `analyze_document`.
 */
final class RemoteFile
{
    /** Délai de téléchargement d'un fichier distant. */
    private const DOWNLOAD_TIMEOUT = 30;

    /**
     * Résout la source choisie en [contenu, nom de fichier].
     *
     * @param  array<string, mixed>  $sources  Clés file_base64 / file_path / file_url.
     * @return array{0: string, 1: string}
     *
     * @throws RuntimeException Message français, destiné au client MCP.
     */
    public static function resolve(array $sources, ?string $filename = null): array
    {
        $fournies = array_filter([
            'file_base64' => $sources['file_base64'] ?? null,
            'file_path' => $sources['file_path'] ?? null,
            'file_url' => $sources['file_url'] ?? null,
        ]);

        if (count($fournies) === 0) {
            throw new RuntimeException('Un des paramètres file_base64, file_path ou file_url est requis.');
        }

        if (count($fournies) > 1) {
            throw new RuntimeException('Les paramètres file_base64, file_path et file_url sont mutuellement exclusifs.');
        }

        return match (true) {
            isset($fournies['file_base64']) => self::fromBase64($fournies['file_base64'], $filename),
            isset($fournies['file_path']) => self::fromPath($fournies['file_path'], $filename),
            default => self::fromUrl($fournies['file_url'], $filename),
        };
    }

    /** @return array{0: string, 1: string} */
    public static function fromBase64(string $base64, ?string $filename): array
    {
        $decoded = base64_decode($base64, strict: true);

        if ($decoded === false) {
            throw new RuntimeException('Le contenu base64 fourni est invalide.');
        }

        return [$decoded, $filename ?? 'document'];
    }

    /**
     * Lit un fichier local, confiné sous MCP_FILE_PATH_PREFIX.
     *
     * @return array{0: string, 1: string}
     */
    public static function fromPath(string $filePath, ?string $filename): array
    {
        $prefix = config('mcp.file_path_prefix');

        if (empty($prefix)) {
            throw new RuntimeException('file_path non disponible : MCP_FILE_PATH_PREFIX n\'est pas configuré sur ce serveur.');
        }

        // realpath() résout « .. » : indispensable, sinon le préfixe se contourne.
        $realPrefix = realpath($prefix);
        $realPath = realpath($filePath);

        if ($realPath === false || $realPrefix === false) {
            throw new RuntimeException('Le chemin de fichier fourni est invalide ou inaccessible.');
        }

        if (! str_starts_with($realPath, $realPrefix . DIRECTORY_SEPARATOR) && $realPath !== $realPrefix) {
            throw new RuntimeException("Accès refusé : le chemin doit être situé sous {$prefix}.");
        }

        if (! is_file($realPath) || ! is_readable($realPath)) {
            throw new RuntimeException('Le fichier est introuvable ou non lisible.');
        }

        $contents = file_get_contents($realPath);

        if ($contents === false) {
            throw new RuntimeException('Impossible de lire le fichier.');
        }

        return [$contents, $filename ?? basename($realPath)];
    }

    /**
     * Télécharge un fichier distant, sous protection anti-SSRF.
     *
     * @return array{0: string, 1: string}
     */
    public static function fromUrl(string $url, ?string $filename): array
    {
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');

        if (! in_array($scheme, ['http', 'https'], strict: true)) {
            throw new RuntimeException('file_url doit utiliser le schéma http ou https.');
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (empty($host)) {
            throw new RuntimeException('file_url doit contenir un nom d\'hôte valide.');
        }

        $pinnedIp = self::resolveAllowedIp($host);

        if ($pinnedIp === null) {
            throw new RuntimeException('file_url refusée : l\'hôte cible pointe vers une adresse IP privée, locale ou réservée (protection anti-SSRF).');
        }

        // Ne PAS suivre les redirections : une URL dont l'hôte initial est public
        // pourrait sinon renvoyer un 3xx vers une IP interne (169.254.169.254…)
        // et contourner le contrôle ci-dessus, l'hôte redirigé n'étant pas revalidé.
        //
        // Et se connecter à l'IP VÉRIFIÉE : sans CURLOPT_RESOLVE, le client résolvait le
        // nom une seconde fois, et un DNS qui change de réponse entre les deux (rebinding)
        // passait le contrôle (GHSA-j4gm-g8m8-93x2, point 15).
        $port = parse_url($url, PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80);
        $options = filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false
            ? []
            : ['curl' => [CURLOPT_RESOLVE => [$host . ':' . $port . ':' . (str_contains($pinnedIp, ':') ? '[' . $pinnedIp . ']' : $pinnedIp)]]];

        $response = Http::withoutRedirecting()->withOptions($options)->timeout(self::DOWNLOAD_TIMEOUT)->get($url);

        if ($response->redirect()) {
            throw new RuntimeException('file_url refusée : les redirections ne sont pas suivies (protection anti-SSRF). Fournissez l\'URL finale directe.');
        }

        if (! $response->successful()) {
            throw new RuntimeException("Impossible de télécharger le fichier (HTTP {$response->status()}).");
        }

        $resolved = $filename ?? basename(parse_url($url, PHP_URL_PATH) ?? '') ?: 'document';

        return [$response->body(), $resolved];
    }

    /**
     * L'hôte résout-il exclusivement vers des adresses publiques ?
     *
     * Toutes les IP (v4 et v6) doivent l'être : un nom qui résout vers une
     * publique ET une privée serait exploitable.
     */
    public static function isHostAllowed(string $host): bool
    {
        return self::resolveAllowedIp($host) !== null;
    }

    /**
     * L'adresse à laquelle se connecter si l'hôte ne résout que vers des adresses
     * publiques ; null sinon. La première est rendue pour être épinglée.
     */
    public static function resolveAllowedIp(string $host): ?string
    {
        // Hôte fourni directement sous forme d'IP littérale (crochets IPv6 compris).
        $literalIp = trim($host, '[]');

        if (filter_var($literalIp, FILTER_VALIDATE_IP) !== false) {
            return self::isPublicIp($literalIp) ? $literalIp : null;
        }

        $ips = [];

        $ipv4 = @gethostbynamel($host);

        if (is_array($ipv4)) {
            $ips = array_merge($ips, $ipv4);
        }

        $aaaaRecords = @dns_get_record($host, DNS_AAAA);

        if (is_array($aaaaRecords)) {
            foreach ($aaaaRecords as $record) {
                if (! empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if ($ips === []) {
            // Résolution impossible : on refuse par précaution.
            return null;
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return null;
            }
        }

        return $ips[0];
    }

    /** IP ni privée, ni loopback, ni réservée. */
    private static function isPublicIp(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        // Garde-fous explicites : selon l'implémentation, les filtres ci-dessus
        // ne couvrent pas toujours ces plages.
        $blockedCidrs = [
            '0.0.0.0/8',      // « ce réseau »
            '100.64.0.0/10',  // CGNAT (adresses partagées, souvent internes chez les hébergeurs)
            '169.254.0.0/16', // link-local IPv4 (métadonnées cloud AWS/GCP/Azure)
            '192.0.0.0/24',   // affectations IETF
            '198.18.0.0/15',  // bancs de test réseau
            '224.0.0.0/4',    // multicast
            'fc00::/7',       // unique local address IPv6
            'fe80::/10',      // link-local IPv6
            'ff00::/8',       // multicast IPv6
            '::1/128',        // loopback IPv6
            '64:ff9b::/96',   // traduction NAT64 : peut viser une IPv4 interne
            '::ffff:0:0/96',  // IPv4 mappée en IPv6 : l'IPv4 sous-jacente n'est pas revérifiée
        ];

        foreach ($blockedCidrs as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return false;
            }
        }

        return true;
    }

    /** Une IP (v4 ou v6) appartient-elle à un bloc CIDR ? */
    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $prefixLength] = explode('/', $cidr);

        $ipBinary = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);

        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $prefixLength = (int) $prefixLength;
        $bytes = intdiv($prefixLength, 8);
        $remainder = $prefixLength % 8;

        if ($bytes > 0 && substr($ipBinary, 0, $bytes) !== substr($subnetBinary, 0, $bytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = ~(0xFF >> $remainder) & 0xFF;

        return (ord($ipBinary[$bytes]) & $mask) === (ord($subnetBinary[$bytes]) & $mask);
    }
}
