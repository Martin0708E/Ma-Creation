<?php
require_once(__DIR__ . '/dgmp_acquisitions/config/database.php');

try {
    $pdo = getConnexion();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Suppression des anciens matériels
    $pdo->exec("DELETE FROM materiels");
    echo "✅ Anciens matériels supprimés<br>";

    // Insertion des matériels
    $stmt = $pdo->prepare("INSERT INTO materiels
        (id_categorie, nom_materiel, description,
         specification_technique, unite_mesure, prix_unitaire)
        VALUES (?, ?, ?, ?, ?, ?)");

    // Ordinateurs (categorie 1)
    $stmt->execute([1,
        'Ordinateur portable Dell Latitude 5540',
        'Ordinateur portable professionnel Dell',
        'Intel Core i5-1335U, 8GB RAM, 256GB SSD, 15.6 FHD',
        'Unite', 350000]);

    $stmt->execute([1,
        'Ordinateur de bureau HP EliteDesk',
        'Ordinateur de bureau professionnel HP',
        'Intel Core i5, 8GB RAM, 256GB SSD, Windows 11 Pro',
        'Unite', 250000]);

    $stmt->execute([1,
        'Lenovo ThinkStation P350',
        'Station de travail haute performance',
        'Intel Core i9, 32GB RAM, 1TB SSD, NVIDIA RTX 3060',
        'Unite', 1250000]);

    echo "✅ Ordinateurs ajoutés<br>";

    // Ecrans (categorie 2)
    $stmt->execute([2,
        'Ecran Dell 24 pouces',
        'Moniteur professionnel Dell Full HD',
        '24 pouces, 1920x1080, IPS, HDMI, DisplayPort',
        'Unite', 180000]);

    echo "✅ Ecrans ajoutés<br>";

    // Reseau (categorie 3)
    $stmt->execute([3,
        'Switch Cisco 24 ports',
        'Switch reseau manageable Cisco',
        '24 ports Gigabit, Layer 2, PoE+, Rack 1U',
        'Unite', 320000]);

    $stmt->execute([3,
        'Bobine cable UTP Cat6 305m',
        'Cable reseau UTP Categorie 6',
        '305m, Cat6, 250MHz, 4 paires torsadees',
        'Bobine', 75000]);

    echo "✅ Matériels réseau ajoutés<br>";

    // Imprimantes (categorie 4)
    $stmt->execute([4,
        'Imprimante Canon MF743Cdw',
        'Imprimante multifonction couleur Canon',
        'A4, Recto-verso, WiFi, 20ppm couleur, Scanner',
        'Unite', 350000]);

    $stmt->execute([4,
        'Cartouche HP 305XL',
        'Cartouche encre HP haute capacite',
        'HP 305XL, Noir et couleur, Haute capacite',
        'Unite', 25000]);

    echo "✅ Imprimantes ajoutées<br>";

    // Stockage (categorie 5)
    $stmt->execute([5,
        'SSD Samsung 1TB',
        'Disque SSD interne Samsung',
        '1TB, NVMe M.2, 3500Mo/s lecture',
        'Unite', 85000]);

    $stmt->execute([5,
        'Synology NAS 4 baies',
        'Serveur NAS Synology 4 baies',
        '4 baies, Intel Celeron J4125, 4GB RAM',
        'Unite', 450000]);

    echo "✅ Stockage ajouté<br>";

    // Securite (categorie 6)
    $stmt->execute([6,
        'Pare-feu Fortinet FortiGate 60F',
        'Pare-feu nouvelle generation Fortinet',
        '10 Gbps, VPN SSL, IPS, Antivirus, 10 ports GbE',
        'Unite', 760000]);

    $stmt->execute([6,
        'Camera IP Hikvision 4MP',
        'Camera de surveillance IP Hikvision',
        '4MP, 2K, IR 30m, H.265+, IP67, PoE, Dome',
        'Unite', 155000]);

    echo "✅ Sécurité ajoutée<br>";

    // Logiciels (categorie 7)
    $stmt->execute([7,
        'Licence Microsoft 365 E3 1 an',
        'Suite Microsoft 365 Entreprise E3',
        '1 utilisateur, 1 an, Teams, Office, Exchange',
        'Licence', 120000]);

    echo "✅ Logiciels ajoutés<br>";

    // Telephonie (categorie 8)
    $stmt->execute([8,
        'Telephone IP Cisco 8851',
        'Telephone IP professionnel Cisco',
        '5 lignes, Ecran couleur 5 pouces, HD Voice, PoE',
        'Unite', 145000]);

    $stmt->execute([8,
        'Samsung Galaxy A54',
        'Smartphone Samsung Galaxy A54',
        '6.4 pouces, 8GB RAM, 256GB, 5G, Android 13',
        'Unite', 230000]);

    echo "✅ Téléphonie ajoutée<br>";

    // Multimedia (categorie 9)
    $stmt->execute([9,
        'Projecteur Epson EB-U50',
        'Videoprojecteur professionnel Epson',
        '3700 lumens, WUXGA 1920x1200, HDMI, WiFi',
        'Unite', 220000]);

    echo "✅ Multimédia ajouté<br>";

    // Alimentation (categorie 10)
    $stmt->execute([10,
        'Onduleur Eaton 1000VA',
        'Onduleur interactif Eaton',
        '1000VA/600W, 8 prises, USB, Autonomie 10min',
        'Unite', 200000]);

    echo "✅ Alimentation ajoutée<br>";

    // Accessoires (categorie 11)
    $stmt->execute([11,
        'Housse pour clavier ergonomique',
        'Housse de protection pour clavier',
        'Compatible claviers standard, Silicone, Lavable',
        'Unite', 12000]);

    echo "✅ Accessoires ajoutés<br>";

    // Tablettes (categorie 12)
    $stmt->execute([12,
        'iPad Air 10.9 pouces',
        'Tablette Apple iPad Air',
        '10.9 pouces, M1, 64GB, WiFi, iPadOS, USB-C',
        'Unite', 490000]);

    echo "✅ Tablettes ajoutées<br><br>";

    // Vérification
    $count = $pdo->query("SELECT COUNT(*) FROM materiels")->fetchColumn();

    echo "========================================<br>";
    echo "🎉 MATÉRIELS AJOUTÉS AVEC SUCCÈS !<br>";
    echo "========================================<br>";
    echo "✅ Total matériels : " . $count . "<br><br>";
    echo "⚠️ SUPPRIMEZ CE FICHIER APRÈS UTILISATION !";

} catch (Exception $e) {
    echo "❌ Erreur : " . $e->getMessage();
}
?>