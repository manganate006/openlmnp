<x-filament-panels::page>
    <style>
        .help-section { background: var(--fi-body-bg, white); border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid var(--fi-border-color, #e5e7eb); margin-bottom: 16px; }
        .help-intro { background: #ecfdf5; border: 1px solid #86efac; border-radius: 12px; padding: 24px; margin-bottom: 24px; }
        .help-intro h2 { font-size: 20px; font-weight: 700; color: #065f46; margin-bottom: 8px; }
        .help-intro p { color: #047857; font-size: 14px; }
        .help-section h3 { font-size: 16px; font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .help-section h3 svg { width: 20px; height: 20px; }
        .help-section p, .help-section li { font-size: 14px; color: var(--fi-fg, #374151); line-height: 1.6; }
        .help-section p { margin-bottom: 12px; }
        .help-section strong { color: var(--fi-fg, #111827); }
        .help-section ul { list-style: none; padding: 0; margin: 0 0 12px 0; }
        .help-section ul li { padding: 8px 0; padding-left: 28px; position: relative; border-bottom: 1px solid var(--fi-border-color, #f3f4f6); }
        .help-section ul li:last-child { border-bottom: none; }
        .help-section ul li::before { content: attr(data-icon); position: absolute; left: 0; top: 8px; font-size: 16px; }
        .help-step { display: flex; gap: 12px; margin-bottom: 12px; }
        .help-step-num { flex-shrink: 0; width: 28px; height: 28px; background: #d1fae5; color: #065f46; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; }
        .help-step-text { font-size: 14px; color: var(--fi-fg, #374151); }
        .help-badge { display: inline-block; padding: 2px 8px; border-radius: 9999px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .help-badge-once { background: #dbeafe; color: #1e40af; }
        .help-badge-regular { background: #fef3c7; color: #92400e; }
        .help-badge-annual { background: #fce7f3; color: #9d174d; }
        .help-category-header { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; padding-bottom: 12px; border-bottom: 2px solid; }
        .help-category-once { border-color: #3b82f6; }
        .help-category-once h3 { color: #1e40af; }
        .help-category-regular { border-color: #f59e0b; }
        .help-category-regular h3 { color: #92400e; }
        .help-category-annual { border-color: #ec4899; }
        .help-category-annual h3 { color: #9d174d; }
        .help-faq dt { font-weight: 500; color: var(--fi-fg, #111827); margin-bottom: 4px; font-size: 14px; }
        .help-faq dd { color: var(--fi-fg-muted, #6b7280); margin-bottom: 16px; font-size: 14px; }
        .help-tip { background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 12px 16px; font-size: 13px; color: #92400e; margin-top: 8px; }
    </style>

    <div style="max-width: 800px;">
        <div class="help-intro">
            <h2>Bienvenue sur OpenLMNP</h2>
            <p>OpenLMNP est un logiciel open source de comptabilit&eacute; pour les propri&eacute;taires en LMNP (Location Meubl&eacute;e Non Professionnelle). Il vous aide &agrave; g&eacute;rer vos biens, calculer vos amortissements, et produire votre liasse fiscale au r&eacute;gime r&eacute;el.</p>
            <p style="margin-top: 8px;">Ce guide est organis&eacute; en <span class="help-badge help-badge-once">une seule fois</span> <span class="help-badge help-badge-regular">r&eacute;guli&egrave;rement</span> <span class="help-badge help-badge-annual">chaque ann&eacute;e</span> pour vous aider &agrave; savoir quoi faire et quand.</p>
        </div>

        {{-- ============================================================ --}}
        {{-- SECTION 1 : UNE SEULE FOIS                                   --}}
        {{-- ============================================================ --}}

        <div class="help-section">
            <div class="help-category-header help-category-once">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:24px;height:24px;color:#3b82f6"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z" /></svg>
                    &Agrave; faire une seule fois <span class="help-badge help-badge-once">mise en route</span>
                </h3>
            </div>

            <div class="help-step"><span class="help-step-num">1</span><div class="help-step-text"><strong>Ajoutez votre bien immobilier</strong> &mdash; Renseignez l'adresse, les surfaces, le prix d'achat et la valeur v&eacute;nale. Les composants d'amortissement (gros &oelig;uvre, toiture, &eacute;lectricit&eacute;, plomberie&hellip;) sont g&eacute;n&eacute;r&eacute;s automatiquement.</div></div>

            <div class="help-step"><span class="help-step-num">2</span><div class="help-step-text"><strong>V&eacute;rifiez la quote-part</strong> &mdash; Si vous louez une partie de votre r&eacute;sidence principale, la quote-part est calcul&eacute;e automatiquement (surface lou&eacute;e &divide; surface totale). Elle s'applique aux charges partag&eacute;es et &agrave; l'amortissement.</div></div>

            <div class="help-step"><span class="help-step-num">3</span><div class="help-step-text"><strong>Renseignez la valeur v&eacute;nale</strong> &mdash; Si vous poss&eacute;diez le bien avant de le mettre en location, indiquez sa valeur estim&eacute;e au d&eacute;but de l'activit&eacute;. C'est cette valeur qui sert de base &agrave; l'amortissement (pas le prix d'achat).</div></div>

            <div class="help-step"><span class="help-step-num">4</span><div class="help-step-text"><strong>Ajustez la part du terrain</strong> &mdash; Le terrain n'est pas amortissable. En g&eacute;n&eacute;ral 15-20&nbsp;%. Sources pour l'estimer : DVF (app.dvf.etalab.gouv.fr), MeilleursAgents, votre notaire.</div></div>

            <div class="help-step"><span class="help-step-num">5</span><div class="help-step-text"><strong>Ajoutez vos emprunts</strong> &mdash; Le tableau d'amortissement est calcul&eacute; automatiquement. Les int&eacute;r&ecirc;ts et l'assurance emprunteur sont d&eacute;duits en charges au prorata de la quote-part.</div></div>

            <div class="help-step"><span class="help-step-num">6</span><div class="help-step-text"><strong>Saisissez vos meubles</strong> &mdash; Les meubles d'une valeur sup&eacute;rieure &agrave; 600&nbsp;&euro; sont amortis (g&eacute;n&eacute;ralement sur 5 &agrave; 10 ans). En dessous, ils passent directement en charges.</div></div>

            <div class="help-tip">
                <strong>Astuce :</strong> Si vous n'avez pas encore ajout&eacute; de bien, l'assistant <strong>Premier lancement</strong> appara&icirc;t automatiquement dans le menu Param&egrave;tres et vous guide pas &agrave; pas.
            </div>
        </div>

        {{-- ============================================================ --}}
        {{-- SECTION 2 : RÉGULIÈREMENT                                    --}}
        {{-- ============================================================ --}}

        <div class="help-section">
            <div class="help-category-header help-category-regular">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:24px;height:24px;color:#f59e0b"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0 3.181 3.183a8.25 8.25 0 0 0 13.803-3.7M4.031 9.865a8.25 8.25 0 0 1 13.803-3.7l3.181 3.182" /></svg>
                    &Agrave; faire r&eacute;guli&egrave;rement <span class="help-badge help-badge-regular">en cours d'ann&eacute;e</span>
                </h3>
            </div>

            <p>Id&eacute;alement chaque mois, ou au minimum chaque trimestre, pour ne rien oublier :</p>

            <ul>
                <li data-icon="&#x1F4B0;"><strong>Saisir les recettes</strong> &mdash; Ajoutez vos loyers manuellement ou importez un fichier CSV depuis Airbnb/Booking (menu <strong>Import Airbnb</strong>). La commission plateforme (~3&nbsp;%) est d&eacute;duite automatiquement. La taxe de s&eacute;jour n'est pas &agrave; inclure.</li>
                <li data-icon="&#x1F4C4;"><strong>Enregistrer les charges</strong> &mdash; Taxe fonci&egrave;re, assurance, &eacute;nergie, entretien, m&eacute;nage&hellip; Indiquez si la charge est 100&nbsp;% d&eacute;di&eacute;e (m&eacute;nage Airbnb) ou partag&eacute;e (la quote-part s'applique automatiquement).</li>
                <li data-icon="&#x1F4CE;"><strong>Joindre les justificatifs</strong> &mdash; Photo ou PDF de chaque facture/re&ccedil;u. Conservation obligatoire : <strong>6 ans minimum</strong> (10 ans recommand&eacute;s).</li>
                <li data-icon="&#x1F6CB;"><strong>Ajouter les meubles achet&eacute;s</strong> &mdash; Nouveau mobilier, &eacute;lectrom&eacute;nager, &eacute;quipements. Si &gt; 600&nbsp;&euro; TTC : amortissement. Sinon : charge directe.</li>
                <li data-icon="&#x1F527;"><strong>Enregistrer les travaux</strong> &mdash; Travaux d'am&eacute;lioration ou de r&eacute;paration sur le bien. Les gros travaux sont amortis, les petites r&eacute;parations passent en charges.</li>
            </ul>

            <div class="help-tip">
                <strong>Astuce :</strong> Plus vous &ecirc;tes r&eacute;gulier dans la saisie, moins la cl&ocirc;ture annuelle sera fastidieuse. Un quart d'heure par mois suffit g&eacute;n&eacute;ralement.
            </div>
        </div>

        {{-- ============================================================ --}}
        {{-- SECTION 3 : CHAQUE ANNÉE                                     --}}
        {{-- ============================================================ --}}

        <div class="help-section">
            <div class="help-category-header help-category-annual">
                <h3>
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="width:24px;height:24px;color:#ec4899"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 9v9.75" /></svg>
                    Chaque ann&eacute;e avant la d&eacute;claration <span class="help-badge help-badge-annual">avril&ndash;juin</span>
                </h3>
            </div>

            <p>La d&eacute;claration de revenus est g&eacute;n&eacute;ralement ouverte d'avril &agrave; juin. Voici la marche &agrave; suivre :</p>

            <div class="help-step"><span class="help-step-num">1</span><div class="help-step-text"><strong>V&eacute;rifiez l'exhaustivit&eacute; des donn&eacute;es</strong> &mdash; Assurez-vous que toutes les recettes, charges et meubles de l'ann&eacute;e &eacute;coul&eacute;e sont bien saisis. Comparez avec vos relev&eacute;s bancaires.</div></div>

            <div class="help-step"><span class="help-step-num">2</span><div class="help-step-text"><strong>Cr&eacute;ez l'exercice fiscal</strong> &mdash; Allez dans <strong>Fiscal &gt; Nouvel exercice</strong> et s&eacute;lectionnez l'ann&eacute;e. L'assistant v&eacute;rifie la coh&eacute;rence de vos donn&eacute;es.</div></div>

            <div class="help-step"><span class="help-step-num">3</span><div class="help-step-text"><strong>Calculez le r&eacute;sultat fiscal</strong> &mdash; Cliquez sur <strong>&laquo;&nbsp;Calculer&nbsp;&raquo;</strong> dans l'exercice. OpenLMNP calcule : recettes &minus; charges &minus; amortissements (plafonn&eacute;s pour ne pas cr&eacute;er de d&eacute;ficit). L'exc&eacute;dent d'amortissement est report&eacute; ind&eacute;finiment.</div></div>

            <div class="help-step"><span class="help-step-num">4</span><div class="help-step-text"><strong>Consultez le simulateur</strong> &mdash; Comparez le r&eacute;gime r&eacute;el avec le micro-BIC pour v&eacute;rifier que le r&eacute;el reste avantageux. Micro-BIC 2026 : abattement 50&nbsp;% (class&eacute;) ou 30&nbsp;% (non class&eacute;).</div></div>

            <div class="help-step"><span class="help-step-num">5</span><div class="help-step-text"><strong>G&eacute;n&eacute;rez la liasse fiscale PDF</strong> &mdash; Cliquez sur <strong>&laquo;&nbsp;PDF&nbsp;&raquo;</strong>. Le document contient les formulaires 2031, 2033-B et 2033-C pr&eacute;remplis.</div></div>

            <div class="help-step"><span class="help-step-num">6</span><div class="help-step-text"><strong>G&eacute;n&eacute;rez le FEC</strong> &mdash; Le Fichier des &Eacute;critures Comptables (18 colonnes norm&eacute;es) est obligatoire en cas de contr&ocirc;le fiscal. Conservez-le pr&eacute;cieusement.</div></div>

            <div class="help-step"><span class="help-step-num">7</span><div class="help-step-text"><strong>D&eacute;clarez sur impots.gouv.fr</strong> &mdash; Reportez le r&eacute;sultat dans la <strong>2042-C-PRO</strong> : case <strong>5NA</strong> si b&eacute;n&eacute;fice, case <strong>5NK</strong> si d&eacute;ficit. Consultez la page <strong>T&eacute;l&eacute;d&eacute;claration</strong> pour un guide d&eacute;taill&eacute;.</div></div>

            <div class="help-tip">
                <strong>Astuce :</strong> Utilisez la <strong>Projection pluriannuelle</strong> (menu Fiscal) pour anticiper l'&eacute;volution de votre r&eacute;sultat fiscal sur les prochaines ann&eacute;es et savoir quand le micro-BIC redeviendra plus int&eacute;ressant.
            </div>
        </div>

        {{-- ============================================================ --}}
        {{-- MÉMO RAPIDE                                                   --}}
        {{-- ============================================================ --}}

        <div class="help-section">
            <h3>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color:#6b7280"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h12M8.25 12h12m-12 5.25h12M3.75 6.75h.007v.008H3.75V6.75Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0ZM3.75 12h.007v.008H3.75V12Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Zm-.375 5.25h.007v.008H3.75v-.008Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" /></svg>
                M&eacute;mo : les notions cl&eacute;s
            </h3>
            <dl class="help-faq">
                <dt>Amortissement</dt>
                <dd>D&eacute;duire chaque ann&eacute;e une fraction de la valeur du bien et des meubles. C'est le principal avantage du r&eacute;gime r&eacute;el. L'amortissement ne peut pas cr&eacute;er de d&eacute;ficit : l'exc&eacute;dent est report&eacute; sur les ann&eacute;es suivantes sans limite.</dd>
                <dt>Prorata temporis</dt>
                <dd>La premi&egrave;re ann&eacute;e d'activit&eacute;, l'amortissement est calcul&eacute; au prorata du nombre de jours restants. OpenLMNP g&egrave;re ce calcul automatiquement.</dd>
                <dt>Quote-part</dt>
                <dd>Si vous louez une partie de votre logement, seule la fraction correspondante des charges et de l'amortissement est d&eacute;ductible (ex : 28&nbsp;% si vous louez 35&nbsp;m&sup2; sur 126&nbsp;m&sup2;).</dd>
                <dt>Micro-BIC vs R&eacute;gime r&eacute;el</dt>
                <dd>Micro-BIC : abattement forfaitaire (50&nbsp;% class&eacute;, 30&nbsp;% non class&eacute;). R&eacute;gime r&eacute;el : d&eacute;duction des charges r&eacute;elles + amortissements. G&eacute;n&eacute;ralement plus avantageux les premi&egrave;res ann&eacute;es.</dd>
            </dl>
        </div>

        {{-- ============================================================ --}}
        {{-- FAQ                                                           --}}
        {{-- ============================================================ --}}

        <div class="help-section">
            <h3>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="color:#6b7280"><path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" /></svg>
                Questions fr&eacute;quentes
            </h3>
            <dl class="help-faq">
                <dt>Est-ce qu'OpenLMNP remplace un expert-comptable ?</dt>
                <dd>Non. C'est un outil d'aide &agrave; la comptabilit&eacute;. Pour les cas complexes ou en cas de doute, consultez un professionnel.</dd>
                <dt>Les donn&eacute;es sont-elles s&eacute;curis&eacute;es ?</dt>
                <dd>OpenLMNP est auto-h&eacute;berg&eacute; : vos donn&eacute;es restent sur votre serveur. Aucune information n'est transmise &agrave; un tiers.</dd>
                <dt>Puis-je g&eacute;rer plusieurs biens ?</dt>
                <dd>Oui. Les calculs fiscaux consolident automatiquement tous vos biens.</dd>
                <dt>Combien de temps conserver les justificatifs ?</dt>
                <dd>6 ans minimum (d&eacute;lai de prescription fiscale). 10 ans recommand&eacute;s pour les amortissements de longue dur&eacute;e.</dd>
                <dt>Quand repasser en micro-BIC ?</dt>
                <dd>Quand les amortissements sont &eacute;puis&eacute;s et que le r&eacute;sultat au r&eacute;el d&eacute;passe celui du micro-BIC. Utilisez la projection pluriannuelle pour anticiper ce moment.</dd>
            </dl>
        </div>

        <p style="text-align:center;font-size:12px;color:#9ca3af;padding:16px;">OpenLMNP &mdash; Logiciel libre sous licence AGPLv3</p>
    </div>
</x-filament-panels::page>
