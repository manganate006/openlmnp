<?php

namespace App\Filament\Resources\Properties\Schemas;

use App\Filament\Schemas\DpeLookup;
use App\Filament\Schemas\MarketValueEstimate;
use App\Models\Property;
use App\Support\DocumentStorage;
use App\Support\Dpe\DpeRules;
use App\Support\Dpe\PropertyDpeVerdict;
use App\Support\MicroBicRules;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

class PropertyForm
{
    public static function configure(Schema $schema): Schema
    {
        $isEdit = $schema->getOperation() === 'edit';

        if ($isEdit) {
            return static::tabsLayout($schema);
        }

        return static::wizardLayout($schema);
    }

    // -------------------------------------------------------------------------
    // Wizard (création)
    // -------------------------------------------------------------------------

    private static function wizardLayout(Schema $schema): Schema
    {
        return $schema
            ->components([
                Wizard::make([
                    Step::make('Informations générales')
                        ->icon('heroicon-o-home-modern')
                        ->description('Nom, type et adresse du bien')
                        ->schema(static::generalFields()),

                    Step::make('Surfaces & Résidence')
                        ->icon('heroicon-o-square-3-stack-3d')
                        ->description('Surfaces pour le calcul de la quote-part')
                        ->schema(static::surfaceFields()),

                    Step::make('Acquisition & Valeur')
                        ->icon('heroicon-o-banknotes')
                        ->description('Prix d\'achat et valeur vénale pour l\'amortissement')
                        ->schema(static::acquisitionFields()),

                    Step::make('Location & Annonces')
                        ->icon('heroicon-o-calendar')
                        ->description('Début de location et liens vers vos annonces')
                        ->schema(static::rentalFields()),
                ])
                ->columnSpanFull()
                ->skippable(),
            ]);
    }

    // -------------------------------------------------------------------------
    // Tabs (édition)
    // -------------------------------------------------------------------------

    private static function tabsLayout(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Bien immobilier')
                    ->tabs([
                        Tab::make('Identité')
                            ->icon('heroicon-o-home-modern')
                            ->schema([
                                ...static::generalFields(),
                                Textarea::make('notes')
                                    ->label('Notes')
                                    ->rows(3)
                                    ->columnSpanFull(),
                            ]),

                        Tab::make('Surfaces & Valeur')
                            ->icon('heroicon-o-square-3-stack-3d')
                            ->schema([
                                ...static::surfaceFields(),
                                ...static::acquisitionFields(),
                            ]),

                        Tab::make('Location')
                            ->icon('heroicon-o-calendar')
                            ->schema(static::rentalFields()),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    // -------------------------------------------------------------------------
    // Champs partagés
    // -------------------------------------------------------------------------

    public static function generalFields(): array
    {
        return [
            DocumentStorage::protect(
                FileUpload::make('photo_path')
                    ->label('Photo du bien')
                    ->image()
                    ->directory(DocumentStorage::directory('photos-biens'))
                    ->getUploadedFileNameForStorageUsing(
                        DocumentStorage::filename('acquisition_date', 'name')
                    )
                    ->automaticallyResizeImagesMode('cover')
                    ->automaticallyResizeImagesToWidth('1280')
                    ->automaticallyResizeImagesToHeight('1280')
                    ->maxSize(10240)
                    ->imagePreviewHeight('250')
                    ->panelLayout('integrated')
                    ->openable()
                    ->preventFilePathTampering(allowFilePathUsing: DocumentStorage::belongsToCurrentUser())
                    ->columnSpanFull()
            ),
            TextInput::make('name')
                ->label('Nom du bien')
                ->placeholder('Ex : La Bastide')
                ->required()
                ->maxLength(255),
            Grid::make(2)->schema([
                Select::make('type')
                    ->label('Type de bien')
                    ->options(Property::typeLabels())
                    ->required()
                    ->default('apartment'),
                Select::make('rental_type')
                    ->label('Type de location')
                    ->options(Property::rentalTypeLabels())
                    ->required()
                    ->default('seasonal')
                    ->live()
                    ->afterStateUpdated(fn (Set $set, $state) => $set(
                        'tourism_classification',
                        MicroBicRules::defaultClassificationForRentalType($state)
                    ))
                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Saisonnier = Airbnb. Longue durée = bail.'),
            ]),
            // Autocomplétion par la Base Adresse Nationale : les trois attributs suffisent,
            // le script est posé sur toutes les pages du panel
            // (`filament/partials/address-autocomplete`). Choisir une suggestion remplit
            // les trois champs d'un coup ; la saisie manuelle reste entière.
            Grid::make(3)->schema([
                TextInput::make('address')
                    ->label('Adresse')
                    ->required()
                    ->extraInputAttributes(['data-address-group' => 'property', 'data-address-role' => 'address'])
                    ->helperText('Commencez à taper : les adresses de la Base Adresse Nationale vous sont proposées.'),
                TextInput::make('city')
                    ->label('Ville')
                    ->required()
                    ->extraInputAttributes(['data-address-group' => 'property', 'data-address-role' => 'city']),
                TextInput::make('postal_code')
                    ->label('Code postal')
                    ->required()
                    ->maxLength(10)
                    ->extraInputAttributes(['data-address-group' => 'property', 'data-address-role' => 'postal_code']),
            ]),
            Placeholder::make('dpe_expiry_alert')
                ->hiddenLabel()
                ->visible(fn (Get $get) => PropertyDpeVerdict::expiryStatus($get('dpe_valid_until')) !== null)
                ->content(fn (Get $get) => self::dpeExpiryContent($get)),
            Grid::make(3)->schema([
                TextInput::make('dpe_number')
                    ->label('Numéro de DPE')
                    ->maxLength(20)
                    ->hintAction(DpeLookup::action())
                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Visible sur votre attestation ADEME.'),
                Select::make('dpe_class')
                    ->label('Classe DPE')
                    ->options(array_combine(DpeRules::CLASSES, DpeRules::CLASSES))
                    ->live()
                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Classe au sens de l\'article L. 173-1-1 (énergie et gaz à effet de serre).'),
                DatePicker::make('dpe_established_at')
                    ->label('Établi le')
                    ->displayFormat('d/m/Y'),
                DatePicker::make('dpe_valid_until')
                    ->label('Valable jusqu\'au')
                    ->displayFormat('d/m/Y')
                    ->live(),
            ]),
            Placeholder::make('dpe_verdict')
                ->hiddenLabel()
                ->visible(fn (Get $get) => filled($get('dpe_class')))
                ->content(fn (Get $get) => self::dpeVerdictContent($get)),
        ];
    }

    /**
     * Verdicts de décence énergétique pour la configuration de location en cours de saisie
     * (bail meublé et/ou meublé de tourisme selon `rental_type` et `residence_situation`).
     *
     * ⚠️ L'exemption du meublé de tourisme ne vaut que pour `residence_situation ===
     * RESIDENCE_PART_OF_RESIDENCE` — voir le commentaire de `Property::dpeVerdicts()`, qui
     * applique la même règle côté modèle.
     *
     * Formulation prudente : « Décent / Non décent », jamais « interdit » — voir DpeRules.
     */
    private static function dpeVerdictContent(Get $get): HtmlString
    {
        $verdicts = PropertyDpeVerdict::forRentalConfiguration(
            (string) $get('dpe_class'),
            (string) $get('rental_type'),
            $get('residence_situation') === Property::RESIDENCE_PART_OF_RESIDENCE,
            (string) $get('postal_code'),
        );

        if ($verdicts === []) {
            return new HtmlString('');
        }

        $rows = '';
        foreach ($verdicts as $verdict) {
            if (! $verdict['applies']) {
                $etat = 'Aucune exigence de classe pour le moment.';
            } else {
                $etat = ($verdict['allowed'] ? 'Décent' : 'Non décent').' (A à '.$verdict['worst_allowed'].' exigé).';
            }

            $next = $verdict['next']
                ? ' Prochaine échéance le '.Carbon::parse($verdict['next']['date'])->format('d/m/Y')
                    .' : A à '.$verdict['next']['worst_allowed'].' exigé.'
                : '';

            $rows .= '<li><strong>'.e($verdict['label']).'</strong> — '.e($etat).e($next).'</li>';
        }

        return new HtmlString(
            '<p>Un logement non décent ne rend pas le bail nul : le locataire peut exiger les '
            .'travaux, et le juge réduire ou suspendre le loyer (article 20-1 de la loi du '
            .'6 juillet 1989).</p><ul>'.$rows.'</ul>'
        );
    }

    private static function dpeExpiryContent(Get $get): HtmlString
    {
        $status = PropertyDpeVerdict::expiryStatus($get('dpe_valid_until'));

        return new HtmlString(match ($status) {
            'expired' => '<p>Ce DPE est expiré : retrouvez-en un à jour ou faites-en établir un nouveau.</p>',
            'soon' => '<p>Ce DPE expire dans moins de six mois : pensez à en faire établir un nouveau.</p>',
            default => '',
        });
    }

    public static function surfaceFields(): array
    {
        return [
            Select::make('residence_situation')
                ->label('Situation du logement')
                ->options(Property::residenceSituationLabels())
                ->required()
                ->default(Property::RESIDENCE_SEPARATE)
                ->live(),
            Placeholder::make('residence_situation_help')
                ->label('')
                ->content(fn (Get $get) => static::residenceSituationHelp($get('residence_situation')))
                ->visible(fn (Get $get) => in_array($get('residence_situation'), [
                    Property::RESIDENCE_PART_OF_RESIDENCE,
                    Property::RESIDENCE_INDEPENDENT_UNIT,
                ], true)),
            Grid::make(2)->schema([
                TextInput::make('total_area')
                    ->label('Surface totale déclarée')
                    ->suffix('m²')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (callable $get, callable $set) => static::syncQuotaShareDisplay($get, $set))
                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: fn (Get $get) => $get('residence_situation') === Property::RESIDENCE_INDEPENDENT_UNIT
                        ? 'Surface de la PROPRIÉTÉ ENTIÈRE (résidence principale comprise), telle que déclarée aux impôts.'
                        : 'Telle que déclarée aux impôts'),
                TextInput::make('rented_area')
                    ->label('Surface louée')
                    ->suffix('m²')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (callable $get, callable $set) => static::syncQuotaShareDisplay($get, $set))
                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: fn (Get $get) => $get('residence_situation') === Property::RESIDENCE_INDEPENDENT_UNIT
                        ? 'Surface du seul logement indépendant (studio, dépendance...).'
                        : 'Quote-part = louée ÷ totale'),
            ]),
            // ⚠️ PAS une colonne du bien : une alternative de saisie à `rented_area`, pour
            // qui connaît sa quote-part (« je loue 27 % de ma résidence ») mais pas le
            // nombre exact de m² loués. `dehydrated(false)` l'exclut de l'enregistrement —
            // c'est `rented_area`, recalculée ici, qui reste la seule valeur persistée.
            TextInput::make('quota_share_percent')
                ->label('Quote-part louée')
                ->suffix('%')
                ->numeric()
                ->step(0.1)
                ->minValue(0)
                ->maxValue(100)
                ->dehydrated(false)
                ->afterStateHydrated(fn (callable $get, callable $set) => static::syncQuotaShareDisplay($get, $set))
                ->live(onBlur: true)
                ->afterStateUpdated(function (callable $get, callable $set) {
                    $total = (float) ($get('total_area') ?? 0);
                    $percent = (float) ($get('quota_share_percent') ?? 0);

                    if ($total <= 0) {
                        return;
                    }

                    $set('rented_area', (string) max(1, (int) round($total * $percent / 100)));
                    static::syncQuotaShareDisplay($get, $set);
                })
                ->helperText('Saisissez SOIT la surface louée ci-dessus, SOIT cette quote-part : l\'autre se calcule automatiquement.'),
        ];
    }

    /**
     * Recalcule l'affichage de `quota_share_percent` depuis `total_area`/`rented_area`
     * courants — jamais l'inverse ici, pour ne pas rejouer `afterStateUpdated` en boucle.
     */
    private static function syncQuotaShareDisplay(callable $get, callable $set): void
    {
        $total = (float) ($get('total_area') ?? 0);
        $rented = (float) ($get('rented_area') ?? 0);

        if ($total <= 0) {
            return;
        }

        $set('quota_share_percent', round($rented / $total * 100, 1));
    }

    /**
     * Rappel fiscal selon la situation choisie — jamais de chiffre incertain : seuls
     * les points de régime (120 jours, art. 35 bis, CFE, THRS) sont stables assez
     * longtemps pour être affirmés sans source datée.
     */
    private static function residenceSituationHelp(?string $situation): HtmlString
    {
        if ($situation === Property::RESIDENCE_INDEPENDENT_UNIT) {
            return new HtmlString(
                '<div class="ctx-tip">'
                . '<strong>Logement indépendant :</strong> pour la loi, c\'est un meublé distinct de votre '
                . 'résidence principale — pas de limite de 120 jours, pas d\'art. 35 bis (ses exonérations '
                . 'ne s\'appliquent qu\'à une partie de la résidence principale elle-même), CFE due en principe, '
                . 'et THRS si un membre du foyer l\'utilise une partie de l\'année. '
                . 'Surface totale = toute la propriété ; surface louée = le seul logement indépendant ; '
                . 'prix/valeur = ceux de la propriété entière. Marquez « dédiées » les charges propres au '
                . 'logement (compteur électrique séparé, travaux du logement, CFE).'
                . '</div>'
            );
        }

        return new HtmlString(
            '<div class="ctx-tip">'
            . '<strong>Pièce(s) de la résidence principale :</strong> la quote-part (surface louée ÷ totale) '
            . 's\'applique à l\'amortissement et aux charges communes. Les travaux et le mobilier 100 % dédiés '
            . 'au logement loué sont amortis en entier.'
            . '</div>'
        );
    }

    public static function acquisitionFields(): array
    {
        return [
            Grid::make(3)->schema([
                TextInput::make('acquisition_price')
                    ->label('Prix d\'achat')
                    ->suffix('€')
                    ->required()
                    ->numeric()
                    ->step(1)
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, '.', '') : null)
                    ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Hors frais de notaire'),
                TextInput::make('notary_fees')
                    ->label('Frais de notaire')
                    ->suffix('€')
                    ->numeric()
                    ->step(1)
                    ->default(0)
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, '.', '') : '0')
                    ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Amortis sur 25 ans, avec quote-part surface'),
                TextInput::make('agency_fees')
                    ->label('Honoraires agence')
                    ->suffix('€')
                    ->numeric()
                    ->step(1)
                    ->default(0)
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, '.', '') : '0')
                    ->dehydrateStateUsing(fn ($state) => (int) round(((float) $state) * 100))
                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Amortis sur 25 ans, avec quote-part surface'),
                DatePicker::make('acquisition_date')
                    ->label('Date d\'achat')
                    ->required()
                    ->displayFormat('d/m/Y'),
            ]),
            // Le traitement des frais d'acquisition est une OPTION du contribuable, prise
            // à l'acquisition (BOI-BIC-CHG-20-20-10). Tant qu'elle était figée sur
            // « amortis sur 25 ans », un bailleur dont le cabinet les avait passés en
            // charges les voyait amortis une seconde fois, sans recours.
            Grid::make(2)->schema([
                Select::make('acquisition_fees_treatment')
                    ->label('Traitement des frais d\'acquisition')
                    ->options(Property::acquisitionFeesTreatmentLabels())
                    ->default(Property::ACQUISITION_FEES_AMORTIZED)
                    ->required()
                    ->live()
                    ->helperText(fn (callable $get) => match ($get('acquisition_fees_treatment') ?? Property::ACQUISITION_FEES_AMORTIZED) {
                        Property::ACQUISITION_FEES_CAPITALIZED => 'Les frais rejoignent le prix du bien : ils se répartissent terrain / bâti comme lui, '
                            . 'donc la part terrain ne s\'amortit pas, et le reste suit la durée de chaque composant. '
                            . 'Votre base amortissable augmente.',
                        Property::ACQUISITION_FEES_EXPENSED => 'Votre comptable les a déduits en une fois l\'année de l\'achat. OpenLMNP ne les amortira donc plus.',
                        Property::ACQUISITION_FEES_EXCLUDED => 'Ils n\'entrent ni dans les charges ni dans les immobilisations : rien n\'en sera repris.',
                        default => 'Les frais forment une immobilisation à part, amortie sur sa propre durée. Ils apparaissent '
                            . 'avec les constructions au bilan (case 028), et votre base amortissable ne bouge pas.',
                    }),
                TextInput::make('acquisition_fees_duration')
                    ->label('Durée d\'amortissement des frais')
                    ->suffix('ans')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(50)
                    ->default(Property::ACQUISITION_FEES_DEFAULT_DURATION)
                    ->visible(fn (callable $get) => ($get('acquisition_fees_treatment') ?? Property::ACQUISITION_FEES_AMORTIZED) === Property::ACQUISITION_FEES_AMORTIZED)
                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'La pratique dominante est 25 ans, alignée sur la durée du gros œuvre. Reprenez celle de votre liasse si elle diffère.'),
            ]),
            // ⚠️ Une valeur vénale renseignée PRIME sur le prix d'acquisition, frais compris :
            // c'est la valeur d'entrée dans l'activité, à laquelle les frais d'une acquisition
            // antérieure n'ont pas à s'ajouter. La combinaison n'est pas refusée — refuser
            // casserait les outils MCP et l'import de dossier —, elle est dite.
            Placeholder::make('acquisition_fees_ignored')
                ->hiddenLabel()
                ->visible(fn (callable $get) => $get('acquisition_fees_treatment') === Property::ACQUISITION_FEES_CAPITALIZED
                    && (int) $get('market_value') > 0)
                ->content('Une valeur vénale est renseignée : c\'est elle qui sert de base, et vos frais '
                    . 'd\'acquisition ne sont donc pas repris. Ils datent de l\'achat, pas de l\'entrée du '
                    . 'bien dans votre activité de loueur.'),
            Grid::make(3)->schema([
                TextInput::make('market_value')
                    ->label('Valeur vénale')
                    ->suffix('€')
                    ->numeric()
                    ->step(1)
                    ->live(onBlur: true)
                    ->formatStateUsing(fn ($state) => $state ? number_format($state / 100, 0, '.', '') : null)
                    ->dehydrateStateUsing(fn ($state) => $state ? (int) round(((float) $state) * 100) : null)
                    ->hintAction(MarketValueEstimate::action())
                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Si bien déjà possédé avant la location'),
                DatePicker::make('market_value_date')
                    ->label('Date estimation')
                    ->displayFormat('d/m/Y'),
                Hidden::make('insee_code'),
                TextInput::make('land_percentage')
                    ->label('Terrain non amortissable')
                    ->suffix('%')
                    ->required()
                    ->numeric()
                    // ⚠️ Deux décimales, et pas un entier. Un acte donne couramment 17,5 % ;
                    // arrondir à 17 ou 18 déplace 875 € de base sur un bien à 175 000 €,
                    // soit plus que les écarts que l'écran de contrôle signale en rouge.
                    ->step(0.01)
                    ->default(15)
                    ->minValue(0)
                    ->maxValue(50)
                    ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Généralement 15-20 %. Les décimales sont acceptées (17,5 par exemple).'),
            ]),
        ];
    }

    public static function rentalFields(): array
    {
        return [
            DatePicker::make('rental_start_date')
                ->label('Date de début de location')
                ->required()
                ->displayFormat('d/m/Y')
                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Date de démarrage du LMNP au régime réel. Sert de point de départ pour le calcul des amortissements (prorata temporis).')
                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Les amortissements démarrent à cette date.'),
            Select::make('tourism_classification')
                ->label('Classement micro-BIC')
                ->options(Property::tourismClassificationLabels())
                ->required()
                ->default(Property::CLASSIFICATION_UNCLASSIFIED)
                ->helperText('Pré-rempli d\'après le type de location, modifiable. Détermine l\'abattement et le plafond du micro-BIC dans le simulateur — sans effet sur le régime réel.')
                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Classé = classement officiel préfecture/Atout France. Un Airbnb courant, sans ce classement, est "non classé".'),
            Select::make('tva_regime')
                ->label('Régime de TVA')
                ->options(Property::tvaRegimeLabels())
                ->required()
                ->default(Property::TVA_EXEMPT)
                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Sélectionnez « Assujetti TVA » si vous fournissez au moins 3 services para-hôteliers sur 4 (petit-déjeuner, linge, ménage, accueil). Vous collecterez alors la TVA à 10 % sur les loyers et pourrez déduire la TVA sur vos charges.'),
            TextInput::make('airbnb_commission_rate')
                ->label('Taux commission Airbnb (hôte)')
                ->suffix('%')
                ->numeric()
                ->step(0.01)
                ->minValue(0)
                ->maxValue(30)
                ->placeholder('18.6')
                ->helperText('Frais de service hôte, TVA comprise. Frais partagés : 3 % + TVA = 3,6 %. Frais hôte uniquement (généralisé en France le 13/10/2026) : 15,5 % + TVA = 18,6 %. Le taux dépend de la date de confirmation de la réservation, pas du séjour.')
                ->hintIcon('heroicon-o-question-mark-circle', tooltip: 'Sert uniquement à reconstituer le brut depuis l\'export « Réservations », qui ne détaille pas la commission. L\'export « Historique des transactions » porte la commission réelle ligne par ligne : préférez-le dès que vos réservations relèvent des deux modèles.'),
            Repeater::make('listing_urls')
                ->label('Liens d\'annonces')
                ->schema([
                    TextInput::make('label')
                        ->label('Plateforme')
                        ->placeholder('Airbnb, Booking...')
                        ->required(),
                    TextInput::make('url')
                        ->label('URL')
                        ->url()
                        ->placeholder('https://...')
                        ->required(),
                ])
                ->columns(2)
                ->addActionLabel('Ajouter un lien')
                ->defaultItems(0),
        ];
    }
}
