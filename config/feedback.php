<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Recueil d'avis
    |--------------------------------------------------------------------------
    |
    | Une invitation ponctuelle à donner son avis sur le logiciel. Elle n'apparaît
    | qu'une fois qu'un utilisateur a passé un peu de temps dans l'app ET y a fait
    | quelque chose de concret : demander un avis à quelqu'un qui vient d'arriver
    | ne renseigne sur rien et agace.
    |
    | Tout est réglable par variable d'environnement, sans rebuild — c'est ce qui
    | permet d'ajuster les seuils à l'usage réel plutôt qu'à une intuition.
    |
    | ⚠️ Toute variable ajoutée ici doit AUSSI être ajoutée à l'allowlist de
    | `docker-entrypoint.sh` : l'entrypoint ne recopie vers `.env` qu'une liste
    | fixe, et une variable oubliée est ignorée en silence en production, `-e` ou
    | pas. `FeedbackRuntimeConfigTest` échoue si l'une d'elles manque à la boucle.
    |
    */

    'enabled' => env('FEEDBACK_ENABLED', true),

    /*
     * Qui peut voir l'invitation : « demo » (sandbox de démonstration) et/ou
     * « user » (compte normal, self-hosted comme cloud). Liste séparée par des
     * virgules. Vide = personne, équivalent à `enabled=false`.
     */
    'audiences' => env('FEEDBACK_AUDIENCES', 'demo,user'),

    /*
     * Mises en forme en lice, tirées au sort à parts égales :
     *   a = modale centrée · b = bandeau bas · c = carte flottante
     *
     * La variante est figée par cookie dès le premier affichage : personne ne doit
     * voir deux mises en forme différentes, sinon on ne mesure plus rien.
     *
     * Le jour où l'une gagne, il suffit d'écrire `FEEDBACK_VARIANTS=a` : le test
     * s'arrête sans redéploiement, et les deux autres restent dans le code au cas où.
     *
     * ⚠️ Le volume de la démonstration (~45 visiteurs / 90 jours) rend une conclusion
     * lente à venir : le rapport hebdomadaire affiche des comptages bruts et refuse de
     * désigner un gagnant sous `min_sample` observations par variante.
     */
    'variants' => env('FEEDBACK_VARIANTS', 'a,b,c'),

    /*
     * Nombre d'affichages par variante en dessous duquel aucune comparaison n'est
     * publiée. 30 est déjà très bas pour une différence de taux — c'est le seuil en
     * dessous duquel le bruit dépasse à coup sûr le signal, pas un seuil de validité.
     */
    'min_sample' => (int) env('FEEDBACK_MIN_SAMPLE', 30),

    /*
     * Temps passé dans la session avant de proposer quoi que ce soit.
     * Repère de départ : sur la démo, la session médiane dure 2 à 5 min.
     */
    'min_seconds' => (int) env('FEEDBACK_MIN_SECONDS', 240),

    /*
     * Nombre d'actions marquantes à avoir franchies dans la session. C'est la
     * moitié du déclencheur qui compte : le temps seul laisserait la modale
     * s'ouvrir devant quelqu'un qui n'a encore rien vu du logiciel.
     */
    'min_actions' => (int) env('FEEDBACK_MIN_ACTIONS', 1),

    /*
     * Les événements qui comptent comme « action marquante ». Ce sont ceux que
     * les pages du panel émettent déjà via `dispatch('analytics', …)`.
     */
    'actions' => env('FEEDBACK_ACTIONS', 'property_added,projection_used,simulator_used,cerfa_generated,fec_exported,teledeclaration_generated,ai_import_used'),

    /*
     * Ancienneté minimale, en jours, pour la seconde invitation — celle qui
     * s'adresse à quelqu'un qui est revenu. Un sandbox de démonstration ne vit
     * que `demo.ttl_hours` : le retour se détecte donc entre deux sandboxes,
     * par le cookie posé au premier passage.
     */
    'return_days' => (int) env('FEEDBACK_RETURN_DAYS', 2),

    /*
     * Délai avant de reproposer à quelqu'un qui a fermé ou déjà répondu.
     */
    'cooldown_days' => (int) env('FEEDBACK_COOLDOWN_DAYS', 30),

    /*
     * Destinataire des retours.
     *
     * ⚠️ Vide par défaut, et ce défaut est un choix, pas un oubli : une instance
     * auto-hébergée chez un tiers ne doit pas expédier silencieusement vers nos
     * serveurs du texte saisi par ses utilisateurs. Sans adresse configurée, le
     * retour est enregistré localement et l'utilisateur se voit proposer de
     * l'envoyer lui-même (lien `mailto:` prérempli) ou d'ouvrir une issue.
     */
    'forward_email' => env('FEEDBACK_FORWARD_EMAIL', ''),

    /*
     * Liens de soutien proposés à qui apprécie le logiciel. Repris de la section
     * « Comment nous soutenir ? » du README.
     */
    'links' => [
        /*
         * Adresse proposée dans le lien `mailto:` quand aucune adresse de transmission
         * n'est configurée — c'est l'utilisateur qui envoie, depuis son propre client mail.
         */
        'contact' => env('FEEDBACK_CONTACT_EMAIL', 'contact@openlmnp.fr'),
        'star' => env('FEEDBACK_URL_STAR', 'https://github.com/manganate006/openlmnp'),
        'sponsor' => env('FEEDBACK_URL_SPONSOR', 'https://github.com/sponsors/manganate006'),
        'discussions' => env('FEEDBACK_URL_DISCUSSIONS', 'https://github.com/manganate006/openlmnp/discussions/new?category=show-and-tell'),
        'issues' => env('FEEDBACK_URL_ISSUES', 'https://github.com/manganate006/openlmnp/issues/new'),
    ],
];
