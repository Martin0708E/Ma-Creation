<?php
if (extension_loaded('pdo_pgsql')) {
    echo "✅ Le driver pdo_pgsql est bien installé !";
} else {
    echo "❌ Le driver pdo_pgsql est TOUJOURS MANQUANT.";
    echo "<br>Extensions installées : " . implode(", ", get_loaded_extensions());
}
?>