<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
verifierConnexion();

$pdo    = getConnexion();
$errors = [];
$infos  = [];

echo "<style>
    body { font-family: Arial; padding: 20px; background: #f0f0f0; }
    .ok  { background: #e8f5e9; border-left: 4px solid green;
           padding: 10px; margin: 8px 0; border-radius: 4px; }
    .err { background: #ffebee; border-left: 4px solid red;
           padding: 10px; margin: 8px 0; border-radius: 4px; }
    .info{ background: #e3f2fd; border-left: 4px solid blue;
           padding: 10px; margin: 8px 0; border-radius: 4px; }
    h2   { color: #1a237e; margin-top: 20px; }
    pre  { background: #fff; padding: 10px; border-radius: 4px;
           overflow-x: auto; }
</style>";

echo "<h1>🔍 Diagnostic Formulaire Demande</h1>";

// ============================================
// TEST 1 : SESSION
// ============================================
echo "<h2>Test 1 : Session Utilisateur</h2>";
if (isset($_SESSION['utilisateur'])) {
    echo "<div class='ok'>✅ Session OK - Utilisateur : 
          <strong>{$_SESSION['utilisateur']['prenom']} 
          {$_SESSION['utilisateur']['nom']}</strong> 
          (Rôle: {$_SESSION['role']})</div>";
} else {
    echo "<div class='err'>❌ Session manquante !</div>";
}

// ============================================
// TEST 2 : CONNEXION BASE DE DONNÉES
// ============================================
echo "<h2>Test 2 : Connexion Base de Données</h2>";
try {
    $test = $pdo->query("SELECT NOW() as heure")->fetch();
    echo "<div class='ok'>✅ Connexion PostgreSQL OK - 
          Heure serveur : {$test['heure']}</div>";
} catch (Exception $e) {
    echo "<div class='err'>❌ Erreur connexion : {$e->getMessage()}</div>";
}

// ============================================
// TEST 3 : TABLE demandes_acquisition
// ============================================
echo "<h2>Test 3 : Structure Table demandes_acquisition</h2>";
try {
    $colonnes = $pdo->query("
        SELECT column_name, data_type, is_nullable
        FROM information_schema.columns
        WHERE table_name = 'demandes_acquisition'
        ORDER BY ordinal_position
    ")->fetchAll();

    echo "<div class='ok'>✅ Table existe - " . count($colonnes) . " colonnes</div>";
    echo "<pre>";
    foreach ($colonnes as $col) {
        echo "• {$col['column_name']} ({$col['data_type']}) 
              - Nullable: {$col['is_nullable']}\n";
    }
    echo "</pre>";

    // Vérifier colonnes importantes
    $noms_colonnes = array_column($colonnes, 'column_name');

    $colonnes_requises = [
        'id_demande', 'reference_demande', 'id_utilisateur',
        'departement_demandeur', 'motif', 'priorite',
        'statut', 'budget_estime', 'id_fournisseur',
        'date_livraison_prevue'
    ];

    foreach ($colonnes_requises as $col) {
        if (in_array($col, $noms_colonnes)) {
            echo "<div class='ok'>✅ Colonne <strong>{$col}</strong> : existe</div>";
        } else {
            echo "<div class='err'>❌ Colonne <strong>{$col}</strong> : MANQUANTE !</div>";
        }
    }

} catch (Exception $e) {
    echo "<div class='err'>❌ Erreur : {$e->getMessage()}</div>";
}

// ============================================
// TEST 4 : TABLE details_demande
// ============================================
echo "<h2>Test 4 : Table details_demande</h2>";
try {
    $cols = $pdo->query("
        SELECT column_name FROM information_schema.columns
        WHERE table_name = 'details_demande'
        ORDER BY ordinal_position
    ")->fetchAll();
    echo "<div class='ok'>✅ Table details_demande : " . count($cols) . " colonnes</div>";
    echo "<pre>";
    foreach ($cols as $c) echo "• {$c['column_name']}\n";
    echo "</pre>";
} catch (Exception $e) {
    echo "<div class='err'>❌ Erreur : {$e->getMessage()}</div>";
}

// ============================================
// TEST 5 : FOURNISSEURS
// ============================================
echo "<h2>Test 5 : Fournisseurs</h2>";
try {
    $fournisseurs = $pdo->query("
        SELECT id_fournisseur, nom_entreprise, statut
        FROM fournisseurs
        WHERE statut = 'actif'
    ")->fetchAll();

    if (count($fournisseurs) > 0) {
        echo "<div class='ok'>✅ " . count($fournisseurs) . " fournisseur(s) actif(s)</div>";
        foreach ($fournisseurs as $f) {
            echo "<div class='info'>🏢 ID:{$f['id_fournisseur']} - 
                  {$f['nom_entreprise']}</div>";
        }
    } else {
        echo "<div class='err'>❌ Aucun fournisseur actif !</div>";
    }
} catch (Exception $e) {
    echo "<div class='err'>❌ Erreur : {$e->getMessage()}</div>";
}

// ============================================
// TEST 6 : MATÉRIELS
// ============================================
echo "<h2>Test 6 : Matériels</h2>";
try {
    $materiels = $pdo->query("
        SELECT COUNT(*) as nb FROM materiels
    ")->fetch();
    echo "<div class='ok'>✅ {$materiels['nb']} matériel(s) disponible(s)</div>";
} catch (Exception $e) {
    echo "<div class='err'>❌ Erreur : {$e->getMessage()}</div>";
}

// ============================================
// TEST 7 : CONTRAINTE STATUT
// ============================================
echo "<h2>Test 7 : Contraintes statut demande</h2>";
try {
    $contraintes = $pdo->query("
        SELECT conname, pg_get_constraintdef(oid) as definition
        FROM pg_constraint
        WHERE conrelid = 'demandes_acquisition'::regclass
        AND contype = 'c'
    ")->fetchAll();

    if (count($contraintes) > 0) {
        foreach ($contraintes as $c) {
            echo "<div class='info'>📌 {$c['conname']} : {$c['definition']}</div>";
        }
    } else {
        echo "<div class='info'>ℹ️ Aucune contrainte CHECK trouvée</div>";
    }
} catch (Exception $e) {
    echo "<div class='err'>❌ Erreur : {$e->getMessage()}</div>";
}

// ============================================
// TEST 8 : SIMULER L'INSERT
// ============================================
echo "<h2>Test 8 : Simulation INSERT Demande</h2>";
try {
    $pdo->beginTransaction();

    $ref_test = 'TEST-' . time();
    $id_fourn = $pdo->query("
        SELECT id_fournisseur FROM fournisseurs
        WHERE statut='actif' LIMIT 1
    ")->fetch()['id_fournisseur'] ?? null;

    if (!$id_fourn) {
        echo "<div class='err'>❌ Aucun fournisseur disponible pour le test !</div>";
        $pdo->rollBack();
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO demandes_acquisition
            (reference_demande, id_utilisateur, departement_demandeur,
             motif, priorite, budget_estime, statut, id_fournisseur)
            VALUES (:ref, :id, :dept, :motif, :prior, :budget, 'en_attente', :fourn)
            RETURNING id_demande
        ");
        $stmt->execute([
            ':ref'   => $ref_test,
            ':id'    => $_SESSION['id_utilisateur'],
            ':dept'  => 'Test Département',
            ':motif' => 'Test motif simulation',
            ':prior' => 'normale',
            ':budget'=> 1000000,
            ':fourn' => $id_fourn
        ]);
        $id_test = $stmt->fetch()['id_demande'];

        echo "<div class='ok'>✅ INSERT simulation réussi ! ID: {$id_test}</div>";
        $pdo->rollBack(); // On annule le test
        echo "<div class='info'>ℹ️ Test annulé (rollback) - pas de données enregistrées</div>";
    }
} catch (Exception $e) {
    $pdo->rollBack();
    echo "<div class='err'>❌ Erreur INSERT : <strong>{$e->getMessage()}</strong></div>";
    echo "<div class='err'>Code erreur : {$e->getCode()}</div>";
}

// ============================================
// TEST 9 : COLONNE date_livraison_prevue
// ============================================
echo "<h2>Test 9 : Colonne date_livraison_prevue dans demandes</h2>";
try {
    $check = $pdo->query("
        SELECT column_name FROM information_schema.columns
        WHERE table_name = 'demandes_acquisition'
        AND column_name = 'date_livraison_prevue'
    ")->fetch();

    if ($check) {
        echo "<div class='ok'>✅ Colonne date_livraison_prevue : existe</div>";
    } else {
        echo "<div class='err'>❌ Colonne date_livraison_prevue : MANQUANTE !</div>";
        echo "<div class='info'>💡 Solution : Exécutez ce SQL dans pgAdmin :<br>
              <pre>ALTER TABLE demandes_acquisition 
ADD COLUMN IF NOT EXISTS date_livraison_prevue DATE;</pre></div>";
    }
} catch (Exception $e) {
    echo "<div class='err'>❌ Erreur : {$e->getMessage()}</div>";
}

// ============================================
// TEST 10 : COLONNE id_fournisseur
// ============================================
echo "<h2>Test 10 : Colonne id_fournisseur dans demandes</h2>";
try {
    $check = $pdo->query("
        SELECT column_name FROM information_schema.columns
        WHERE table_name = 'demandes_acquisition'
        AND column_name = 'id_fournisseur'
    ")->fetch();

    if ($check) {
        echo "<div class='ok'>✅ Colonne id_fournisseur : existe</div>";
    } else {
        echo "<div class='err'>❌ Colonne id_fournisseur : MANQUANTE !</div>";
        echo "<div class='info'>💡 Solution SQL :<br>
              <pre>ALTER TABLE demandes_acquisition 
ADD COLUMN IF NOT EXISTS id_fournisseur INT 
REFERENCES fournisseurs(id_fournisseur);</pre></div>";
    }
} catch (Exception $e) {
    echo "<div class='err'>❌ Erreur : {$e->getMessage()}</div>";
}

// ============================================
// RÉSUMÉ FINAL
// ============================================
echo "<h2>📊 Résumé</h2>";
echo "<div class='info'>
    <p>✅ = OK | ❌ = Problème à corriger</p>
    <p>Corrigez tous les ❌ puis retestez la soumission du formulaire.</p>
    <p><strong>Supprimez ce fichier après utilisation !</strong></p>
</div>";

echo "<br><a href='nouvelle_demande.php' 
     style='padding:12px 24px;background:#1a237e;color:white;
            border-radius:8px;text-decoration:none;font-weight:bold'>
     → Retourner au formulaire demande
</a>";
?>