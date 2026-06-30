<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
verifierConnexion();
verifierRole(['admin']);

$pdo     = getConnexion();
$success = clean($_GET['success'] ?? '');
$erreur  = clean($_GET['erreur']  ?? '');

$materiels = $pdo->query("
    SELECT m.*, c.nom_categorie
    FROM materiels m
    JOIN categories_materiel c ON m.id_categorie = c.id_categorie
    ORDER BY c.nom_categorie, m.nom_materiel
")->fetchAll();

$categories = $pdo->query("
    SELECT * FROM categories_materiel ORDER BY nom_categorie
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acquisitions Informatiques</title>
    <!-- FAVICON -->
    <?php include '../includes/head.php'; ?>
    <link rel="stylesheet" href="../assets/css/style.css">
    <?php include '../includes/pwa_head.php'; ?>
</head>
<body>

<?php include '../includes/navbar.php'; ?>

<div class="main-container">
    <?php include '../includes/sidebar.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <div>
                <h1>🖥️ Gestion des Matériels</h1>
                <p class="page-subtitle">
                    Définissez les prix unitaires de chaque matériel
                </p>
            </div>
            <button onclick="ouvrirModal('modalAjouter')"
                    class="btn btn-primary">
                ➕ Ajouter Matériel
            </button>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">✅ <?= $success ?></div>
        <?php endif; ?>
        <?php if ($erreur): ?>
            <div class="alert alert-danger">⚠️ <?= $erreur ?></div>
        <?php endif; ?>

        <!-- Info -->

        <!-- Tableau -->
        <div class="card">
            <div class="card-header">
                <h2>Liste des Matériels
                    <span class="badge bg-primary">
                        <?= count($materiels) ?>
                    </span>
                </h2>
                <input type="text"
                       id="rechercheMateriel"
                       class="form-control"
                       style="width:250px"
                       placeholder="🔍 Rechercher..."
                       onkeyup="filtrerTableau()">
            </div>
            <div class="card-body p-0">
                <table class="table" id="tableMateriel">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Catégorie</th>
                            <th>Matériel</th>
                            <th>Spécifications</th>
                            <th>Unité</th>
                            <th>💰 Prix Unitaire</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($materiels as $i => $m): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td>
                                <span class="badge bg-info">
                                    <?= clean($m['nom_categorie']) ?>
                                </span>
                            </td>
                            <td>
                                <strong><?= clean($m['nom_materiel']) ?></strong>
                            </td>
                            <td>
                                <small>
                                    <?= clean($m['specification_technique'] ?? '—') ?>
                                </small>
                            </td>
                            <td><?= clean($m['unite_mesure'] ?? '—') ?></td>
                            <td>
                                <?php if ($m['prix_unitaire'] > 0): ?>
                                <strong style="color:var(--primary)">
                                    <?= formaterMontant($m['prix_unitaire']) ?>
                                </strong>
                                <?php else: ?>
                                <span class="badge bg-danger">
                                    ⚠️ Prix non défini
                                </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-btns">
                                    <button onclick="ouvrirModifier(
                                        <?= $m['id_materiel'] ?>,
                                        '<?= addslashes($m['nom_materiel']) ?>',
                                        <?= $m['id_categorie'] ?>,
                                        '<?= addslashes($m['description'] ?? '') ?>',
                                        '<?= addslashes($m['specification_technique'] ?? '') ?>',
                                        '<?= addslashes($m['unite_mesure'] ?? '') ?>',
                                        <?= $m['prix_unitaire'] ?? 0 ?>
                                    )" class="btn btn-sm btn-warning">
                                        ✏️
                                    </button>
                                    <button onclick="confirmerSuppression(
                                        <?= $m['id_materiel'] ?>,
                                        '<?= addslashes($m['nom_materiel']) ?>'
                                    )" class="btn btn-sm btn-danger">
                                        🗑️
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<!-- MODAL AJOUTER -->
<div class="modal-overlay" id="modalAjouter">
    <div class="modal-box">
        <div class="modal-header">
            <h3>➕ Ajouter un Matériel</h3>
            <button onclick="fermerModal('modalAjouter')"
                    class="modal-close">✕</button>
        </div>
        <form method="POST" action="traitement.php">
            <input type="hidden" name="action" value="ajouter">
            <div class="modal-body">
                <div class="form-group">
                    <label>Catégorie *</label>
                    <select name="id_categorie"
                            class="form-control" required>
                        <option value="">-- Choisir --</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id_categorie'] ?>">
                            <?= clean($cat['nom_categorie']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Nom du Matériel *</label>
                    <input type="text" name="nom_materiel"
                           class="form-control" required
                           placeholder="Ex: Ordinateur Bureau HP">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description"
                              class="form-control" rows="2"
                              placeholder="Description..."></textarea>
                </div>
                <div class="form-group">
                    <label>Spécifications Techniques</label>
                    <textarea name="specification_technique"
                              class="form-control" rows="2"
                              placeholder="Ex: Intel Core i5, 8GB RAM...">
                    </textarea>
                </div>
                <div class="form-row">
                    <div class="form-group col-6">
                        <label>Unité de Mesure</label>
                        <select name="unite_mesure" class="form-control">
                            <option value="Unite">Unité</option>
                            <option value="Licence">Licence</option>
                            <option value="Boite">Boîte</option>
                            <option value="Lot">Lot</option>
                        </select>
                    </div>
                    <div class="form-group col-6">
                        <label>💰 Prix Unitaire (FCFA) *</label>
                        <input type="number"
                               name="prix_unitaire"
                               class="form-control"
                               placeholder="Ex: 350000"
                               min="0" step="1000" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button"
                        onclick="fermerModal('modalAjouter')"
                        class="btn btn-secondary">Annuler</button>
                <button type="submit" class="btn btn-primary">
                    💾 Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL MODIFIER -->
<div class="modal-overlay" id="modalModifier">
    <div class="modal-box">
        <div class="modal-header">
            <h3>✏️ Modifier le Matériel</h3>
            <button onclick="fermerModal('modalModifier')"
                    class="modal-close">✕</button>
        </div>
        <form method="POST" action="traitement.php">
            <input type="hidden" name="action" value="modifier">
            <input type="hidden" name="id_materiel" id="mod_id">
            <div class="modal-body">
                <div class="form-group">
                    <label>Catégorie *</label>
                    <select name="id_categorie" id="mod_categorie"
                            class="form-control" required>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id_categorie'] ?>">
                            <?= clean($cat['nom_categorie']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Nom du Matériel *</label>
                    <input type="text" name="nom_materiel"
                           id="mod_nom" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" id="mod_description"
                              class="form-control" rows="2"></textarea>
                </div>
                <div class="form-group">
                    <label>Spécifications</label>
                    <textarea name="specification_technique"
                              id="mod_spec"
                              class="form-control" rows="2"></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group col-6">
                        <label>Unité</label>
                        <select name="unite_mesure" id="mod_unite"
                                class="form-control">
                            <option value="Unite">Unité</option>
                            <option value="Licence">Licence</option>
                            <option value="Boite">Boîte</option>
                            <option value="Lot">Lot</option>
                        </select>
                    </div>
                    <div class="form-group col-6">
                        <label>💰 Prix Unitaire (FCFA) *</label>
                        <input type="number" name="prix_unitaire"
                               id="mod_prix" class="form-control"
                               min="0" step="1000" required>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button"
                        onclick="fermerModal('modalModifier')"
                        class="btn btn-secondary">Annuler</button>
                <button type="submit" class="btn btn-warning">
                    ✏️ Modifier
                </button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL SUPPRIMER -->
<div class="modal-overlay" id="modalSupprimer">
    <div class="modal-box modal-sm">
        <div class="modal-header">
            <h3>🗑️ Confirmer Suppression</h3>
            <button onclick="fermerModal('modalSupprimer')"
                    class="modal-close">✕</button>
        </div>
        <form method="POST" action="traitement.php">
            <input type="hidden" name="action" value="supprimer">
            <input type="hidden" name="id_materiel" id="sup_id">
            <div class="modal-body">
                <p>Supprimer le matériel :</p>
                <p><strong id="sup_nom"
                           style="color:var(--danger)"></strong> ?</p>
            </div>
            <div class="modal-footer">
                <button type="button"
                        onclick="fermerModal('modalSupprimer')"
                        class="btn btn-secondary">Annuler</button>
                <button type="submit" class="btn btn-danger">
                    🗑️ Supprimer
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
function ouvrirModal(id)  { document.getElementById(id).classList.add('show'); }
function fermerModal(id)  { document.getElementById(id).classList.remove('show'); }
document.querySelectorAll('.modal-overlay').forEach(m => {
    m.addEventListener('click', e => {
        if (e.target === m) fermerModal(m.id);
    });
});
function ouvrirModifier(id, nom, cat, desc, spec, unite, prix) {
    document.getElementById('mod_id').value          = id;
    document.getElementById('mod_nom').value         = nom;
    document.getElementById('mod_categorie').value   = cat;
    document.getElementById('mod_description').value = desc;
    document.getElementById('mod_spec').value        = spec;
    document.getElementById('mod_unite').value       = unite;
    document.getElementById('mod_prix').value        = prix;
    ouvrirModal('modalModifier');
}
function confirmerSuppression(id, nom) {
    document.getElementById('sup_id').value         = id;
    document.getElementById('sup_nom').textContent  = nom;
    ouvrirModal('modalSupprimer');
}
function filtrerTableau() {
    const val  = document.getElementById('rechercheMateriel').value.toLowerCase();
    document.querySelectorAll('#tableMateriel tbody tr').forEach(row => {
        row.style.display =
            row.textContent.toLowerCase().includes(val) ? '' : 'none';
    });
}
</script>
</body>
</html>