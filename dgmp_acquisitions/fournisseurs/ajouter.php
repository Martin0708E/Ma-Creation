<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
verifierConnexion();
verifierRole(['admin', 'responsable']);

$pdo    = getConnexion();
$erreur = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nom      = trim($_POST['nom_entreprise'] ?? '');
    $contact  = trim($_POST['contact_nom'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $tel      = trim($_POST['telephone'] ?? '');
    $adresse  = trim($_POST['adresse'] ?? '');
    $ville    = trim($_POST['ville'] ?? '');
    $pays     = trim($_POST['pays'] ?? 'Burkina Faso');
    $registre = trim($_POST['numero_registre'] ?? '');

    if (empty($nom) || empty($email)) {
        $erreur = "Le nom et l'email sont obligatoires.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erreur = "Email invalide.";
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO fournisseurs
                (nom_entreprise, contact_nom, email, telephone, adresse, ville, pays, numero_registre)
                VALUES (:nom, :contact, :email, :tel, :adresse, :ville, :pays, :registre)
            ");
            $stmt->execute([
                ':nom'      => $nom,
                ':contact'  => $contact,
                ':email'    => $email,
                ':tel'      => $tel,
                ':adresse'  => $adresse,
                ':ville'    => $ville,
                ':pays'     => $pays,
                ':registre' => $registre
            ]);

            logAction($pdo, $_SESSION['id_utilisateur'],
                      "Ajout fournisseur: {$nom}", 'fournisseurs');

            header('Location: index.php?success=' . urlencode("Fournisseur {$nom} ajouté avec succès !"));
            exit();
        } catch (PDOException $e) {
            $erreur = $e->getCode() == 23505
                ? "Cet email est déjà utilisé par un autre fournisseur."
                : "Erreur : " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <?php include '../includes/pwa_head.php'; ?>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajouter Fournisseur — DGMP</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="main-container">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <h1>➕ Ajouter un Fournisseur</h1>
            <a href="index.php" class="btn btn-secondary">← Retour</a>
        </div>

        <?php if ($erreur): ?>
            <div class="alert alert-danger">⚠️ <?= $erreur ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><h2>🏢 Informations du Fournisseur</h2></div>
            <div class="card-body">
                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group col-6">
                            <label>Nom de l'Entreprise *</label>
                            <input type="text" name="nom_entreprise" 
                                   class="form-control" required
                                   value="<?= clean($_POST['nom_entreprise'] ?? '') ?>">
                        </div>
                        <div class="form-group col-6">
                            <label>Nom du Contact</label>
                            <input type="text" name="contact_nom" class="form-control"
                                   value="<?= clean($_POST['contact_nom'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-6">
                            <label>Email *</label>
                            <input type="email" name="email" class="form-control" required
                                   value="<?= clean($_POST['email'] ?? '') ?>">
                        </div>
                        <div class="form-group col-6">
                            <label>Téléphone</label>
                            <input type="text" name="telephone" class="form-control"
                                   value="<?= clean($_POST['telephone'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Adresse</label>
                        <textarea name="adresse" class="form-control" rows="2"><?= clean($_POST['adresse'] ?? '') ?></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-4">
                            <label>Ville</label>
                            <input type="text" name="ville" class="form-control"
                                   value="<?= clean($_POST['ville'] ?? 'Ouagadougou') ?>">
                        </div>
                        <div class="form-group col-4">
                            <label>Pays</label>
                            <input type="text" name="pays" class="form-control"
                                   value="<?= clean($_POST['pays'] ?? 'Burkina Faso') ?>">
                        </div>
                        <div class="form-group col-4">
                            <label>N° Registre Commerce</label>
                            <input type="text" name="numero_registre" class="form-control"
                                   value="<?= clean($_POST['numero_registre'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">💾 Enregistrer</button>
                        <a href="index.php" class="btn btn-secondary">Annuler</a>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>

<?php include '../includes/footer.php'; ?>
</body>
</html>