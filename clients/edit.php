<?php
// Démarrer la session
session_start();

// Chemin racine de l'application
$root_path = dirname(__DIR__);

// Inclure les fichiers de configuration et de fonctions
if (file_exists($root_path . '/config/database.php')) {
    require_once $root_path . '/config/database.php';
}

if (file_exists($root_path . '/includes/functions.php')) {
    require_once $root_path . '/includes/functions.php';
}

// Vérifier si l'utilisateur est connecté
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
// Utilisateur temporaire pour éviter l'erreur (comme dans la page create.php)
$currentUser = [
    'name' => 'Utilisateur Test',
    'role' => 'Administrateur'
];

// Vérifier si l'ID du client est fourni
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$client_id = intval($_GET['id']);

// Connexion à la base de données
$database = new Database();
$db = $database->getConnection();

// Récupérer les données du client et son type
try {
    $query = "SELECT c.*, tc.type AS type_client 
              FROM clients c 
              JOIN type_client tc ON c.type_client_id = tc.id 
              WHERE c.id = :id LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $client_id);
    $stmt->execute();

    if ($stmt->rowCount() > 0) {
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!isset($client['delai_paiement'])) {
            $client['delai_paiement'] = 0;
        }
    } else {
        header('Location: index.php');
        exit;
    }
} catch (PDOException $e) {
    $errors['database'] = 'Erreur lors de la récupération du client : ' . $e->getMessage();
}

// Traitement du formulaire
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validation des données
    if ($client['type_client'] === 'client' && empty($_POST['nom'])) {
        $errors['nom'] = 'Le nom est requis';
    }

    if ($client['type_client'] === 'client' && empty($_POST['prenom'])) {
        $errors['prenom'] = 'Le prénom est requis pour un client';
    }

    if ($client['type_client'] === 'societe') {
        if (empty($_POST['raison_sociale'])) {
            $errors['raison_sociale'] = 'La raison sociale est requise pour une société';
        }
        if (empty($_POST['registre_rcc'])) {
            $errors['registre_rcc'] = 'Le registre RCC est requis pour une société';
        }
         // Validation du délai de paiement
         if (isset($_POST['delai_paiement'])) {
            $delaiPaiement = intval($_POST['delai_paiement']);
            if ($delaiPaiement < 0 || $delaiPaiement > 60) {
                $errors['delai_paiement'] = 'Le délai de paiement doit être compris entre 0 et 60 jours';
            }
        }
    }

    if (empty($_POST['email'])) {
        $errors['email'] = 'L\'email est requis';
    } elseif (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'L\'email n\'est pas valide';
    }

    if (empty($_POST['telephone'])) {
        $errors['telephone'] = 'Le téléphone est requis';
    }

    // Si aucune erreur, mettre à jour le client
    if (empty($errors)) {
        try {
            // Préparer la requête de mise à jour
            if ($client['type_client'] === 'client') {
                $query = "UPDATE clients SET 
                          nom = :nom, 
                          prenom = :prenom, 
                          email = :email, 
                          telephone = :telephone, 
                          adresse = :adresse, 
                          code_postal = :code_postal, 
                          ville = :ville, 
                          notes = :notes
                          WHERE id = :id";
            } else {
                $query = "UPDATE clients SET 
                          raison_sociale = :raison_sociale, 
                          registre_rcc = :registre_rcc, 
                          email = :email, 
                          telephone = :telephone, 
                          adresse = :adresse, 
                          code_postal = :code_postal, 
                          ville = :ville, 
                          notes = :notes,
                          delai_paiement = :delai_paiement
                          WHERE id = :id";
            }

            $stmt = $db->prepare($query);

            // Binder les paramètres communs
            $stmt->bindParam(':id', $client_id);            
            $stmt->bindParam(':email', $_POST['email']);
            $stmt->bindParam(':telephone', $_POST['telephone']);
            $stmt->bindParam(':adresse', $_POST['adresse']);
            $stmt->bindParam(':code_postal', $_POST['code_postal']);
            $stmt->bindParam(':ville', $_POST['ville']);
            $stmt->bindParam(':notes', $_POST['notes']);

            // Binder les paramètres spécifiques
            if ($client['type_client'] === 'client') {
                $stmt->bindParam(':nom', $_POST['nom']);
                $stmt->bindParam(':prenom', $_POST['prenom']);
            } else {
                $stmt->bindParam(':raison_sociale', $_POST['raison_sociale']);
                $stmt->bindParam(':registre_rcc', $_POST['registre_rcc']);
                $delaiPaiement = isset($_POST['delai_paiement']) ? intval($_POST['delai_paiement']) : 0;
                $stmt->bindParam(':delai_paiement', $delaiPaiement, PDO::PARAM_INT);
            }

            // Exécuter la requête
            $stmt->execute();
            $success = true;
            header('Location: index.php');
            exit;
            // Mettre à jour les données du client pour l'affichage
            //$client = array_merge($client, $_POST);
        } catch (PDOException $e) {
            $errors['database'] = 'Erreur lors de la mise à jour du client : ' . $e->getMessage();
        }
    }
}

// Inclure l'en-tête
include $root_path . '/includes/header.php';
?>

<div class="flex h-screen bg-gray-100">
    <!-- Sidebar -->
    <?php include $root_path . '/includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 overflow-y-auto">
        <div class="bg-white shadow-sm">
            <div class="container mx-auto px-6 py-4 flex justify-between items-center">
                <h1 class="text-2xl font-semibold text-gray-800">Modifier un client</h1>
            </div>
        </div>

        <div class="container mx-auto px-6 py-8">
            <!-- Afficher les messages -->
            <?php if ($success): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded">
                    <p class="font-bold">Succès !</p>
                    <p>Le client a été mis à jour avec succès.</p>
                </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded">
                    <p class="font-bold">Erreur !</p>
                    <ul>
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="p-6">
                    <!-- Formulaire -->
                    <form action="edit.php?id=<?php echo $client_id; ?>" method="POST">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Type de client (lecture seule) -->
                            <div class="md:col-span-2">
                                <label class="block text-sm font-medium text-gray-700">Type de client</label>
                                <input type="text" value="<?php echo htmlspecialchars($client['type_client']); ?>" class="w-full px-4 py-2 bg-gray-100 border border-gray-300 rounded-lg" readonly>
                            </div>

                            <!-- Nom -->
                            <?php if ($client['type_client'] === 'client'): ?>
                            <div>
                                <label for="nom" class="block text-sm font-medium text-gray-700">Nom <span class="text-red-500">*</span></label>
                                <input type="text" id="nom" name="nom" value="<?php echo htmlspecialchars($client['nom']); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" required>
                            </div>

                            <!-- Champs spécifiques -->
                           
                                <div id="prenom-container">
                                    <label for="prenom" class="block text-sm font-medium text-gray-700">Prénom <span class="text-red-500">*</span></label>
                                    <input type="text" id="prenom" name="prenom" value="<?php echo htmlspecialchars($client['prenom']); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" required>
                                </div>
                            <?php else: ?>
                                <div id="raison-sociale-container">
                                    <label for="raison_sociale" class="block text-sm font-medium text-gray-700">Raison Sociale <span class="text-red-500">*</span></label>
                                    <input type="text" id="raison_sociale" name="raison_sociale" value="<?php echo htmlspecialchars($client['raison_sociale']); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" required>
                                </div>
                                <div id="registre-rcc-container">
                                    <label for="registre_rcc" class="block text-sm font-medium text-gray-700">Registre RCC <span class="text-red-500">*</span></label>
                                    <input type="text" id="registre_rcc" name="registre_rcc" value="<?php echo htmlspecialchars($client['registre_rcc']); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" required>
                                </div>
                                 <!-- Délai de paiement pour les sociétés -->
                                 <div id="delai-paiement-container">
                                    <label for="delai_paiement_select" class="block text-sm font-medium text-gray-700">Délai de paiement</label>
                                    <div class="flex flex-col space-y-2">
                                        <select id="delai_paiement_select" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                                            <option value="">Sélectionnez un délai ou saisissez une valeur personnalisée</option>
                                            <option value="0" <?php echo ($client['delai_paiement'] == 0) ? 'selected' : ''; ?>>0 jour</option>
                                            <option value="14" <?php echo ($client['delai_paiement'] == 14) ? 'selected' : ''; ?>>2 semaines (14 jours)</option>
                                            <option value="30" <?php echo ($client['delai_paiement'] == 30) ? 'selected' : ''; ?>>1 mois (30 jours)</option>
                                            <option value="60" <?php echo ($client['delai_paiement'] == 60) ? 'selected' : ''; ?>>2 mois (60 jours)</option>
                                            <option value="custom" <?php echo (!in_array($client['delai_paiement'], [0, 14, 30, 60])) ? 'selected' : ''; ?>>Personnalisé</option>
                                        </select>
                                        
                                        <div id="custom_delai_container" class="<?php echo (!in_array($client['delai_paiement'], [0, 14, 30, 60])) ? '' : 'hidden'; ?> flex items-center">
                                            <input type="number" id="delai_paiement" name="delai_paiement" min="0" max="60" value="<?php echo htmlspecialchars($client['delai_paiement']); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                                            <span class="ml-2 text-sm text-gray-500">jours (max 60)</span>
                                        </div>
                                    </div>
                                    <p class="mt-1 text-sm text-gray-500">Nombre de jours accordés pour le paiement des factures.</p>
                                </div>
                            <?php endif; ?>

                            <!-- Email -->
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700">Email <span class="text-red-500">*</span></label>
                                <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($client['email']); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" required>
                            </div>

                            <!-- Téléphone -->
                            <div>
                                <label for="telephone" class="block text-sm font-medium text-gray-700">Téléphone <span class="text-red-500">*</span></label>
                                <input type="text" id="telephone" name="telephone" value="<?php echo htmlspecialchars($client['telephone']); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" required>
                            </div>

                            <!-- Adresse -->
                            <div class="md:col-span-2">
                                <label for="adresse" class="block text-sm font-medium text-gray-700">Adresse</label>
                                <input type="text" id="adresse" name="adresse" value="<?php echo htmlspecialchars($client['adresse']); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <!-- Code Postal -->
                            <div>
                                <label for="code_postal" class="block text-sm font-medium text-gray-700">Code Postal</label>
                                <input type="text" id="code_postal" name="code_postal" value="<?php echo htmlspecialchars($client['code_postal']); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <!-- Ville -->
                            <div>
                                <label for="ville" class="block text-sm font-medium text-gray-700">Ville</label>
                                <input type="text" id="ville" name="ville" value="<?php echo htmlspecialchars($client['ville']); ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <!-- Notes -->
                            <div class="md:col-span-2">
                                <label for="notes" class="block text-sm font-medium text-gray-700">Notes</label>
                                <textarea id="notes" name="notes" rows="4" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500"><?php echo htmlspecialchars($client['notes']); ?></textarea>
                            </div>
                        </div>

                        <!-- Boutons -->
                        <div class="mt-8 flex justify-end space-x-3">
                            <a href="index.php" class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">Annuler</a>
                            <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">Enregistrer</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const typeClient = "<?php echo $client['type_client']; ?>";
    const prenomField = document.querySelector('[name="prenom"]');
    const raisonSocialeField = document.querySelector('[name="raison_sociale"]');
    const registreRCCField = document.querySelector('[name="registre_rcc"]');
    
    // Éléments pour le délai de paiement
    const delaiPaiementSelect = document.getElementById('delai_paiement_select');
    const customDelaiContainer = document.getElementById('custom_delai_container');
    const delaiPaiementInput = document.getElementById('delai_paiement');

    // Gestion du délai de paiement
    if (delaiPaiementSelect && delaiPaiementInput) {
        delaiPaiementSelect.addEventListener('change', function() {
            const selectedValue = this.value;
            
            if (selectedValue === 'custom') {
                // Afficher le champ personnalisé
                customDelaiContainer.classList.remove('hidden');
                delaiPaiementInput.focus();
            } else if (selectedValue !== '') {
                // Masquer le champ personnalisé et définir la valeur sélectionnée
                customDelaiContainer.classList.add('hidden');
                delaiPaiementInput.value = selectedValue;
            }
        });
        
        // S'assurer que la valeur initiale est correcte
        if (!customDelaiContainer.classList.contains('hidden')) {
            delaiPaiementSelect.value = 'custom';
        } else if (delaiPaiementInput.value) {
            const value = parseInt(delaiPaiementInput.value);
            if ([0, 14, 30, 60].includes(value)) {
                delaiPaiementSelect.value = value.toString();
            } else {
                delaiPaiementSelect.value = 'custom';
                customDelaiContainer.classList.remove('hidden');
            }
        }
    }

    // Validation du délai de paiement
    if (delaiPaiementInput) {
        delaiPaiementInput.addEventListener('input', function() {
            let value = parseInt(this.value);
            if (isNaN(value)) {
                this.value = 0;
            } else if (value < 0) {
                this.value = 0;
            } else if (value > 60) {
                this.value = 60;
            }
        });
    }

    if (typeClient === 'client') {
        if (prenomField) prenomField.parentElement.style.display = 'block';
        if (raisonSocialeField) raisonSocialeField.parentElement.style.display = 'none';
        if (registreRCCField) registreRCCField.parentElement.style.display = 'none';
        if (delaiPaiementSelect) delaiPaiementSelect.parentElement.parentElement.style.display = 'none';
    } else if (typeClient === 'societe') {
        if (nomField) nomField.parentElement.style.display = 'none';
        if (prenomField) prenomField.parentElement.style.display = 'none';
        if (raisonSocialeField) raisonSocialeField.parentElement.style.display = 'block';
        if (registreRCCField) registreRCCField.parentElement.style.display = 'block';
        if (delaiPaiementSelect) delaiPaiementSelect.parentElement.parentElement.style.display = 'block';
    }
});
</script>

<?php include $root_path . '/includes/footer.php'; ?>
