<x-filament-panels::page>
    <style>
        .help-section { background: var(--fi-body-bg, white); border-radius: 12px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid var(--fi-border-color, #e5e7eb); margin-bottom: 16px; }
        .help-intro { background: #ecfdf5; border: 1px solid #86efac; border-radius: 12px; padding: 24px; margin-bottom: 24px; }
        .help-intro h2 { font-size: 20px; font-weight: 700; color: #065f46; margin-bottom: 8px; }
        .help-intro p { color: #047857; font-size: 14px; }
        .help-section h3 { font-size: 16px; font-weight: 600; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
        .help-section h3 svg { width: 20px; height: 20px; color: #10b981; }
        .help-section p { font-size: 14px; color: var(--fi-fg, #374151); margin-bottom: 12px; line-height: 1.6; }
        .help-section strong { color: var(--fi-fg, #111827); }
        .help-step { display: flex; gap: 12px; margin-bottom: 12px; }
        .help-step-num { flex-shrink: 0; width: 28px; height: 28px; background: #d1fae5; color: #065f46; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; }
        .help-step-text { font-size: 14px; color: var(--fi-fg, #374151); }
        .help-faq dt { font-weight: 500; color: var(--fi-fg, #111827); margin-bottom: 4px; font-size: 14px; }
        .help-faq dd { color: var(--fi-fg-muted, #6b7280); margin-bottom: 16px; font-size: 14px; }
    </style>

    <div style="max-width: 800px;">
        <div class="help-intro">
            <h2>Bienvenue sur OpenLMNP</h2>
            <p>OpenLMNP est un logiciel open source de comptabilité pour les propriétaires en LMNP (Location Meublée Non Professionnelle). Il vous aide à gérer vos biens, calculer vos amortissements, et produire votre liasse fiscale au régime réel.</p>
        </div>

        <div class="help-section">
            <h3>Parcours recommandé</h3>
            <div class="help-step"><span class="help-step-num">1</span><div class="help-step-text"><strong>Ajoutez votre bien immobilier</strong> — Renseignez l'adresse, les surfaces, le prix d'achat et la valeur vénale. Les composants d'amortissement sont générés automatiquement.</div></div>
            <div class="help-step"><span class="help-step-num">2</span><div class="help-step-text"><strong>Saisissez vos recettes</strong> — Ajoutez vos loyers manuellement ou importez un fichier CSV depuis Airbnb/Booking.</div></div>
            <div class="help-step"><span class="help-step-num">3</span><div class="help-step-text"><strong>Enregistrez vos charges</strong> — Taxe foncière, assurance, énergie, entretien... Indiquez si la charge est 100% dédiée ou partagée (quote-part automatique).</div></div>
            <div class="help-step"><span class="help-step-num">4</span><div class="help-step-text"><strong>Ajoutez vos emprunts</strong> — Le tableau d'amortissement est calculé automatiquement. Les intérêts sont déduits en charges au prorata.</div></div>
            <div class="help-step"><span class="help-step-num">5</span><div class="help-step-text"><strong>Consultez le simulateur</strong> — Comparez le régime réel avec le micro-BIC pour vérifier que le réel reste avantageux.</div></div>
            <div class="help-step"><span class="help-step-num">6</span><div class="help-step-text"><strong>Générez votre liasse fiscale</strong> — Créez un exercice fiscal, cliquez sur « Calculer » puis « PDF ».</div></div>
        </div>

        <div class="help-section">
            <h3>Biens immobiliers</h3>
            <p><strong>Quote-part :</strong> Si vous louez une partie de votre résidence principale, la quote-part est calculée automatiquement (surface louée ÷ surface totale). Elle s'applique aux charges partagées et à l'amortissement.</p>
            <p><strong>Valeur vénale :</strong> Si vous possédiez le bien avant de le mettre en location, renseignez sa valeur estimée au début de l'activité. C'est cette valeur qui sert de base à l'amortissement.</p>
            <p><strong>Part du terrain :</strong> Le terrain n'est pas amortissable. En général 15-20%. Sources : DVF (app.dvf.etalab.gouv.fr), MeilleursAgents, notaire.</p>
            <p><strong>Composants :</strong> Générés automatiquement (gros œuvre 50 ans, toiture 25 ans, installations électriques 25 ans, étanchéité 15 ans, agencements 15 ans, plomberie 15 ans).</p>
        </div>

        <div class="help-section">
            <h3>Recettes</h3>
            <p><strong>Montants :</strong> Saisissez directement en euros. La conversion en centimes est automatique.</p>
            <p><strong>Commission plateforme :</strong> La commission Airbnb (~3%) est déduite automatiquement du calcul fiscal.</p>
            <p><strong>Taxe de séjour :</strong> Non incluse dans les recettes imposables.</p>
            <p><strong>Import Airbnb :</strong> Exportez votre historique depuis Airbnb en CSV puis uploadez-le dans Import Airbnb. Les doublons sont détectés par code de confirmation.</p>
        </div>

        <div class="help-section">
            <h3>Charges</h3>
            <p><strong>Charge 100% dédiée :</strong> Le montant total est déduit (ex : ménage Airbnb, commission plateforme).</p>
            <p><strong>Charge partagée :</strong> La quote-part surface est appliquée automatiquement (ex : taxe foncière, électricité).</p>
            <p><strong>Justificatif :</strong> Joignez une photo ou un PDF. Conservation recommandée : 6 ans minimum.</p>
        </div>

        <div class="help-section">
            <h3>Emprunts</h3>
            <p><strong>Tableau d'amortissement :</strong> Généré automatiquement à la création. Les intérêts sont calculés mois par mois.</p>
            <p><strong>Intérêts déductibles :</strong> Déduits en charges au prorata de la quote-part. L'assurance emprunteur est également déductible.</p>
        </div>

        <div class="help-section">
            <h3>Amortissements</h3>
            <p><strong>Principe :</strong> Déduire chaque année une fraction de la valeur du bien. C'est le principal avantage du régime réel.</p>
            <p><strong>Plafonnement :</strong> L'amortissement ne peut pas créer de déficit. L'excédent est reporté indéfiniment.</p>
            <p><strong>Prorata temporis :</strong> La première année, l'amortissement est calculé au prorata des jours restants.</p>
        </div>

        <div class="help-section">
            <h3>Exercices fiscaux</h3>
            <p><strong>Calculer :</strong> Recalcule le résultat fiscal (recettes - charges - amortissements plafonnés).</p>
            <p><strong>PDF :</strong> Récapitulatif avec le détail du résultat (formulaires 2031, 2033-B, 2033-C).</p>
            <p><strong>FEC :</strong> Fichier des Écritures Comptables (18 colonnes normées). Obligatoire en cas de contrôle.</p>
            <p><strong>Déclaration :</strong> Reportez le résultat dans la 2042-C-PRO (case 5NA bénéfice, 5NK déficit).</p>
        </div>

        <div class="help-section">
            <h3>Simulateur</h3>
            <p><strong>Micro-BIC 2026 :</strong> Abattement 50% (classé) ou 30% (non classé).</p>
            <p><strong>Régime réel :</strong> Déduction charges réelles + amortissements. Généralement plus avantageux.</p>
            <p><strong>Quand repasser en micro-BIC ?</strong> Quand les amortissements sont épuisés et que le résultat réel dépasse le micro-BIC.</p>
        </div>

        <div class="help-section">
            <h3>Questions fréquentes</h3>
            <dl class="help-faq">
                <dt>Est-ce qu'OpenLMNP remplace un expert-comptable ?</dt>
                <dd>Non. C'est un outil d'aide. Pour les cas complexes, consultez un professionnel.</dd>
                <dt>Les données sont-elles sécurisées ?</dt>
                <dd>OpenLMNP est auto-hébergé : vos données restent sur votre serveur.</dd>
                <dt>Puis-je gérer plusieurs biens ?</dt>
                <dd>Oui. Les calculs fiscaux consolident tous les biens.</dd>
            </dl>
        </div>

        <p style="text-align:center;font-size:12px;color:#9ca3af;padding:16px;">OpenLMNP v0.1 — Logiciel libre sous licence AGPLv3</p>
    </div>
</x-filament-panels::page>
