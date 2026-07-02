<?php
require_once(__DIR__ . '/dgmp_acquisitions/config/database.php');

try {
    $pdo = getConnexion();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // ============================================
    // SUPPRESSION DES TABLES EXISTANTES
    // ============================================
    $pdo->exec("DROP TABLE IF EXISTS historique_actions CASCADE");
    $pdo->exec("DROP TABLE IF EXISTS notifications CASCADE");
    $pdo->exec("DROP TABLE IF EXISTS inventaire CASCADE");
    $pdo->exec("DROP TABLE IF EXISTS details_livraison CASCADE");
    $pdo->exec("DROP TABLE IF EXISTS livraisons CASCADE");
    $pdo->exec("DROP TABLE IF EXISTS commandes CASCADE");
    $pdo->exec("DROP TABLE IF EXISTS validations CASCADE");
    $pdo->exec("DROP TABLE IF EXISTS details_demande CASCADE");
    $pdo->exec("DROP TABLE IF EXISTS demandes_acquisition CASCADE");
    $pdo->exec("DROP TABLE IF EXISTS fournisseurs CASCADE");
    $pdo->exec("DROP TABLE IF EXISTS materiels CASCADE");
    $pdo->exec("DROP TABLE IF EXISTS categories_materiel CASCADE");
    $pdo->exec("DROP TABLE IF EXISTS utilisateurs CASCADE");
    echo "✅ Anciennes tables supprimées<br>";

    // ============================================
    // TABLE : utilisateurs
    // ============================================
    $pdo->exec("CREATE TABLE utilisateurs (
        id_utilisateur    SERIAL PRIMARY KEY,
        nom               VARCHAR(100) NOT NULL,
        prenom            VARCHAR(100) NOT NULL,
        email             VARCHAR(150) UNIQUE NOT NULL,
        mot_de_passe      VARCHAR(255) NOT NULL,
        role              VARCHAR(50) NOT NULL
                          CHECK (role IN (
                              'admin','responsable',
                              'agent','validateur'
                          )),
        departement       VARCHAR(100),
        telephone         VARCHAR(20),
        statut            BOOLEAN DEFAULT TRUE,
        statut_inscription VARCHAR(50) DEFAULT 'en_attente'
                          CHECK (statut_inscription IN (
                              'en_attente','approuve','rejete'
                          )),
        date_creation     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "✅ Table utilisateurs créée<br>";

    // ============================================
    // TABLE : categories_materiel
    // ============================================
    $pdo->exec("CREATE TABLE categories_materiel (
        id_categorie  SERIAL PRIMARY KEY,
        nom_categorie VARCHAR(100) NOT NULL,
        description   TEXT,
        date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "✅ Table categories_materiel créée<br>";

    // ============================================
    // TABLE : materiels
    // ============================================
    $pdo->exec("CREATE TABLE materiels (
        id_materiel             SERIAL PRIMARY KEY,
        id_categorie            INT REFERENCES categories_materiel(id_categorie),
        nom_materiel            VARCHAR(150) NOT NULL,
        description             TEXT,
        specification_technique TEXT,
        unite_mesure            VARCHAR(50),
        prix_unitaire           DECIMAL(15,2) DEFAULT 0,
        date_creation           TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "✅ Table materiels créée<br>";

    // ============================================
    // TABLE : fournisseurs
    // ============================================
    $pdo->exec("CREATE TABLE fournisseurs (
        id_fournisseur  SERIAL PRIMARY KEY,
        nom_entreprise  VARCHAR(200) NOT NULL,
        nom             VARCHAR(100),
        prenom          VARCHAR(100),
        email           VARCHAR(150) UNIQUE NOT NULL,
        telephone       VARCHAR(20),
        adresse         TEXT,
        ville           VARCHAR(100),
        pays            VARCHAR(100),
        numero_registre VARCHAR(100),
        statut          VARCHAR(50) DEFAULT 'actif'
                        CHECK (statut IN ('actif','inactif','suspendu')),
        date_inscription TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "✅ Table fournisseurs créée<br>";

    // ============================================
    // TABLE : demandes_acquisition
    // ============================================
    $pdo->exec("CREATE TABLE demandes_acquisition (
        id_demande            SERIAL PRIMARY KEY,
        reference_demande     VARCHAR(50) UNIQUE NOT NULL,
        id_utilisateur        INT REFERENCES utilisateurs(id_utilisateur),
        departement_demandeur VARCHAR(100) NOT NULL,
        motif                 TEXT NOT NULL,
        priorite              VARCHAR(50) DEFAULT 'normale'
                              CHECK (priorite IN ('urgente','haute','normale','basse')),
        statut                VARCHAR(50) DEFAULT 'en_attente',
        budget_estime         DECIMAL(15,2),
        date_demande          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        date_validation       TIMESTAMP,
        commentaire           TEXT
    )");
    echo "✅ Table demandes_acquisition créée<br>";

    // ============================================
    // TABLE : details_demande
    // ============================================
    $pdo->exec("CREATE TABLE details_demande (
        id_detail               SERIAL PRIMARY KEY,
        id_demande              INT REFERENCES demandes_acquisition(id_demande),
        id_materiel             INT REFERENCES materiels(id_materiel),
        quantite                INT NOT NULL,
        prix_unitaire_estime    DECIMAL(15,2),
        specification_complementaire TEXT
    )");
    echo "✅ Table details_demande créée<br>";

    // ============================================
    // TABLE : validations
    // ============================================
    $pdo->exec("CREATE TABLE validations (
        id_validation     SERIAL PRIMARY KEY,
        id_demande        INT REFERENCES demandes_acquisition(id_demande),
        id_validateur     INT REFERENCES utilisateurs(id_utilisateur),
        niveau_validation INT DEFAULT 1,
        decision          VARCHAR(50) CHECK (decision IN ('approuve','rejete')),
        commentaire       TEXT,
        date_validation   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "✅ Table validations créée<br>";

    // ============================================
    // TABLE : commandes
    // ============================================
    $pdo->exec("CREATE TABLE commandes (
        id_commande                 SERIAL PRIMARY KEY,
        id_demande                  INT REFERENCES demandes_acquisition(id_demande),
        id_fournisseur              INT REFERENCES fournisseurs(id_fournisseur),
        reference_commande          VARCHAR(100) UNIQUE NOT NULL,
        montant_total               DECIMAL(15,2) DEFAULT 0,
        date_commande               DATE NOT NULL,
        date_livraison_prevue       DATE,
        statut                      VARCHAR(50) DEFAULT 'en_attente_validation',
        commentaire_responsable     TEXT,
        date_validation_responsable TIMESTAMP,
        date_creation               TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "✅ Table commandes créée<br>";

    // ============================================
    // TABLE : livraisons
    // ============================================
    $pdo->exec("CREATE TABLE livraisons (
        id_livraison      SERIAL PRIMARY KEY,
        id_commande       INT REFERENCES commandes(id_commande),
        id_receptionnaire INT REFERENCES utilisateurs(id_utilisateur),
        date_livraison    DATE NOT NULL,
        statut            VARCHAR(50) CHECK (statut IN ('conforme','non_conforme','partielle')),
        observation       TEXT,
        date_creation     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "✅ Table livraisons créée<br>";

    // ============================================
    // TABLE : details_livraison
    // ============================================
    $pdo->exec("CREATE TABLE details_livraison (
        id_detail_livraison SERIAL PRIMARY KEY,
        id_livraison        INT REFERENCES livraisons(id_livraison),
        id_materiel         INT REFERENCES materiels(id_materiel),
        quantite_commandee  INT NOT NULL,
        quantite_livree     INT NOT NULL,
        observation         TEXT
    )");
    echo "✅ Table details_livraison créée<br>";

    // ============================================
    // TABLE : inventaire
    // ============================================
    $pdo->exec("CREATE TABLE inventaire (
        id_inventaire SERIAL PRIMARY KEY,
        id_materiel   INT REFERENCES materiels(id_materiel),
        id_livraison  INT REFERENCES livraisons(id_livraison),
        numero_serie  VARCHAR(100),
        etat          VARCHAR(50) CHECK (etat IN ('neuf','bon','moyen','mauvais')),
        localisation  VARCHAR(150),
        date_entree   DATE DEFAULT CURRENT_DATE,
        date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "✅ Table inventaire créée<br>";

    // ============================================
    // TABLE : notifications
    // ============================================
    $pdo->exec("CREATE TABLE notifications (
        id_notification SERIAL PRIMARY KEY,
        id_utilisateur  INT REFERENCES utilisateurs(id_utilisateur),
        message         TEXT NOT NULL,
        type            VARCHAR(50) CHECK (type IN ('info','alerte','succes','erreur')),
        lu              BOOLEAN DEFAULT FALSE,
        date_creation   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "✅ Table notifications créée<br>";

    // ============================================
    // TABLE : historique_actions
    // ============================================
    $pdo->exec("CREATE TABLE historique_actions (
        id_historique     SERIAL PRIMARY KEY,
        id_utilisateur    INT REFERENCES utilisateurs(id_utilisateur),
        action            VARCHAR(255) NOT NULL,
        table_concernee   VARCHAR(100),
        id_enregistrement INT,
        date_action       TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    echo "✅ Table historique_actions créée<br><br>";

    // ============================================
    // DONNÉES : UTILISATEURS
    // Mot de passe pour tous : password
    // ============================================
    $pdo->exec("INSERT INTO utilisateurs
        (nom, prenom, email, mot_de_passe, role, departement, telephone, statut, statut_inscription)
        VALUES
        ('ADMIN','Systeme','martinjuniorettien@gmail.com',
        'Ettien0708','admin','Direction Generale','+225 07 08 49 27 42',TRUE,'approuve'),

        ('RESPONSABLE','Jean','jean.responsable@dgmp.bf',
        'JeanRespo',
        'responsable','Direction Informatique','+225 70 00 00 02',TRUE,'approuve'),

        ('AGENT','Marie','marie.agent@dgmp.bf',
        'MarieAgent',
        'agent','Comptabilite','+225 76 00 00 03',TRUE,'approuve'),

        ('VALIDATEUR','Paul','paul.validateur@dgmp.bf',
        'PaulValide',
        'validateur','Direction Generale','+225 65 00 00 04',TRUE,'approuve')
    ");
    echo "✅ Utilisateurs créés<br>";

    // ============================================
    // DONNÉES : CATÉGORIES
    // ============================================
    $pdo->exec("INSERT INTO categories_materiel (nom_categorie, description) VALUES
        ('Ordinateurs','PC, Laptops, Stations de travail'),
        ('Ecrans','Moniteurs et ecrans'),
        ('Reseau','Switches, routeurs, cables reseau'),
        ('Imprimantes','Imprimantes et consommables'),
        ('Stockage','SSD, NAS et solutions de stockage'),
        ('Securite','Pare-feux, cameras et securite'),
        ('Logiciels','Licences et logiciels'),
        ('Telephonie','Telephones IP et mobiles'),
        ('Multimedia','Projecteurs et equipements multimedia'),
        ('Alimentation','Onduleurs et alimentation electrique'),
        ('Accessoires','Accessoires et peripheriques divers'),
        ('Tablettes','Tablettes et appareils mobiles')
    ");
    echo "✅ Catégories créées<br>";

    // ============================================
    // DONNÉES : FOURNISSEURS
    // ============================================
    $pdo->exec("INSERT INTO fournisseurs
        (nom_entreprise, email, telephone, adresse, ville, pays, statut)
        VALUES
        ('CFAO Technologies CI','contact@cfao-ci.com','+225 27 20 21 00 00','Abidjan, Plateau','Abidjan','Cote d''Ivoire','actif'),
        ('Gras Savoye Informatique','info@gsinformatique.ci','+225 27 22 40 00 00','Abidjan, Cocody','Abidjan','Cote d''Ivoire','actif'),
        ('TechnoPlus CI','contact@technoplus.ci','+225 27 21 11 33 44','Abidjan, Marcory','Abidjan','Cote d''Ivoire','actif'),
        ('Inova Solutions','sales@inovasolutions.ci','+225 27 22 55 66 77','Abidjan, Cocody','Abidjan','Cote d''Ivoire','actif'),
        ('NetCare Distribution','contact@netcare.ci','+225 27 24 33 22 11','Abidjan, Yopougon','Abidjan','Cote d''Ivoire','actif'),
        ('Alpha Informatique','contact@alphainformatique.ci','+225 27 23 44 55 66','Abidjan, Treichville','Abidjan','Cote d''Ivoire','actif'),
        ('Digital Services CI','hello@digitalservices.ci','+225 27 25 66 77 88','Abidjan, Cocody','Abidjan','Cote d''Ivoire','actif'),
        ('Global Hardware SA','info@globalhardware.ci','+225 27 26 11 22 33','Abidjan, Zone Industrielle','Abidjan','Cote d''Ivoire','actif'),
        ('Solutions et Co','contact@solutionsco.ci','+225 27 28 44 88 99','Abidjan, Plateau','Abidjan','Cote d''Ivoire','actif')
    ");
    echo "✅ Fournisseurs créés<br><br>";

    echo "<br>========================================<br>";
    echo "🎉 BASE DE DONNÉES CRÉÉE AVEC SUCCÈS !<br>";
    echo "========================================<br><br>";
    echo "✅ Vous pouvez maintenant vous connecter avec :<br>";
    echo "📧 Email    : martinjuniorettien@gmail.com<br>";
    echo "🔑 Password : Ettien0708<br>";
    echo "<br>";
    echo "⚠️ IMPORTANT : Supprimez ce fichier init_db.php après utilisation !";

} catch (Exception $e) {
    echo "❌ Erreur : " . $e->getMessage();
}
?>