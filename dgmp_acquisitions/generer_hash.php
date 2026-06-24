<?php
// ============================================
// GÉNÉRATEUR DE HASH - UTILISEZ UNE SEULE FOIS
// Supprimez ce fichier après utilisation !
// ============================================

$mot_de_passe = 'Ettien0708';
$hash = password_hash($mot_de_passe, PASSWORD_DEFAULT);

echo "<h2>Hash généré :</h2>";
echo "<p><code>" . $hash . "</code></p>";
echo "<hr>";
echo "<p>Copiez ce hash et mettez à jour la base de données :</p>";
echo "<pre>
UPDATE utilisateurs 
SET mot_de_passe = '{$hash}'
WHERE email IN (
    'admin@dgmp.bf',
    'jean.ouedraogo@dgmp.bf', 
    'marie.kabore@dgmp.bf',
    'paul.some@dgmp.bf'
);
</pre>";
echo "<p style='color:red'>⚠️ SUPPRIMEZ CE FICHIER APRÈS UTILISATION !</p>";
?>