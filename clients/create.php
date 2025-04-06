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

// Vérifier si l'utilisateur est connecté, sinon rediriger vers la page de connexion
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Utilisateur temporaire pour éviter l'erreur
$currentUser = [
    'name' => 'Utilisateur Test',
    'role' => 'Administrateur'
];

// Connexion à la base de données
$database = new Database();
$db = $database->getConnection();

// Récupérer les types de clients depuis la base de données
$typesQuery = "SELECT * FROM type_client";
$typesStmt = $db->prepare($typesQuery);
$typesStmt->execute();
$clientTypes = $typesStmt->fetchAll(PDO::FETCH_ASSOC);

// Traitement du formulaire
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validation des données
    if (empty($_POST['type_client'])) {
        $errors['type_client'] = 'Le type de client est requis';
    }

    $clientType = $_POST['type_client'] ?? '';

    // Validation spécifique au type de client
    if ($clientType == 'client') {
        if (empty($_POST['prenom'])) {
            $errors['prenom'] = 'Le prénom est requis pour un client';
        }
        if (empty($_POST['nom'])) {
            $errors['nom'] = 'Le nom est requis';
        }
    } elseif ($clientType == 'societe') {
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

    // Si aucune erreur, insérer les données en base
    if (empty($errors)) {
        try {
            $db->beginTransaction();
    
            // Rechercher l'ID du type de client
            $clientTypeQuery = "SELECT id FROM type_client WHERE type = :type";
            $clientTypeStmt = $db->prepare($clientTypeQuery);
            $clientTypeStmt->bindParam(':type', $_POST['type_client']);
            $clientTypeStmt->execute();
            $clientTypeResult = $clientTypeStmt->fetch(PDO::FETCH_ASSOC);
    
            if ($clientTypeResult) {
                $clientTypeId = $clientTypeResult['id'];
            } else {
                throw new Exception('Type de client invalide');
            }
    
            // Requête d'insertion
            if ($_POST['type_client'] == 'client') {
                $query = "INSERT INTO clients (type_client_id, nom, prenom, email, telephone, adresse, code_postal, ville, date_creation, notes) 
                          VALUES (:type_client, :nom, :prenom, :email, :telephone, :adresse, :code_postal, :ville, :date_creation, :notes)";
            } else {
                $query = "INSERT INTO clients (type_client_id, raison_sociale, registre_rcc, email, telephone, adresse, code_postal, ville, date_creation, notes, delai_paiement) 
                          VALUES (:type_client, :raison_sociale, :registre_rcc, :email, :telephone, :adresse, :code_postal, :ville, :date_creation, :notes, :delai_paiement)";
            }
    
            $stmt = $db->prepare($query);
    
            // Champs communs
            $stmt->bindParam(':type_client', $clientTypeId);
            $stmt->bindParam(':email', $_POST['email']);
            $stmt->bindParam(':telephone', $_POST['telephone']);
            $stmt->bindParam(':adresse', $_POST['adresse']);
            $stmt->bindParam(':code_postal', $_POST['code_postal']);
            $stmt->bindParam(':ville', $_POST['ville']);
            $date_creation = date('Y-m-d');
            $stmt->bindParam(':date_creation', $date_creation);
            $stmt->bindParam(':notes', $_POST['notes']);
    
            // Champs spécifiques
            if ($_POST['type_client'] == 'client') {
                
                $stmt->bindParam(':nom', $_POST['nom']);
                $stmt->bindParam(':prenom', $_POST['prenom']);
            } else {
                $stmt->bindParam(':raison_sociale', $_POST['raison_sociale']);
                $stmt->bindParam(':registre_rcc', $_POST['registre_rcc']);
                $delaiPaiement = isset($_POST['delai_paiement']) ? intval($_POST['delai_paiement']) : 0;
                $stmt->bindParam(':delai_paiement', $delaiPaiement, PDO::PARAM_INT);
            }
    
            $stmt->execute();
            $db->commit();
    
            $success = true;
            header('Location: index.php');
            exit;
        } catch (Exception $e) {
            $db->rollBack();
            $errors['database'] = 'Erreur lors de la création du client : ' . $e->getMessage();
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
                <h1 class="text-2xl font-semibold text-gray-800">Ajouter un client</h1>
            </div>
        </div>

        <div class="container mx-auto px-6 py-8">
            <?php if ($success): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded">
                    <p class="font-bold">Succès !</p>
                    <p>Le client a été créé avec succès.</p>
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
                    <form action="create.php" method="POST" id="create-client-form">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Type de client -->
                            <div class="md:col-span-2">
                                <label for="type_client" class="block text-sm font-medium text-gray-700">Type de client <span class="text-red-500">*</span></label>
                                <select id="type_client" name="type_client" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" required>
                                    <option value="">Sélectionnez un type</option>
                                    <option value="client" <?php echo (isset($_POST['type_client']) && $_POST['type_client'] == 'client') ? 'selected' : ''; ?>>Client</option>
                                    <option value="societe" <?php echo (isset($_POST['type_client']) && $_POST['type_client'] == 'societe') ? 'selected' : ''; ?>>Société</option>
                                </select>
                            </div>

                            <!-- Nom -->
                            <div id="nom-container">
                                <label for="nom" class="block text-sm font-medium text-gray-700">Nom <span class="text-red-500">*</span></label>
                                <input type="text" id="nom" name="nom" value="<?php echo isset($_POST['nom']) ? htmlspecialchars($_POST['nom']) : ''; ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" >
                            </div>

                            <!-- Prénom (pour client) -->
                            <div id="prenom-container">
                                <label for="prenom" class="block text-sm font-medium text-gray-700">Prénom <span class="text-red-500">*</span></label>
                                <input type="text" id="prenom" name="prenom" value="<?php echo isset($_POST['prenom']) ? htmlspecialchars($_POST['prenom']) : ''; ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <!-- Raison Sociale (pour société) -->
                            <div id="raison-sociale-container" class="hidden">
                                <label for="raison_sociale" class="block text-sm font-medium text-gray-700">Raison Sociale <span class="text-red-500">*</span></label>
                                <input type="text" id="raison_sociale" name="raison_sociale" value="<?php echo isset($_POST['raison_sociale']) ? htmlspecialchars($_POST['raison_sociale']) : ''; ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <!-- Registre RCC (pour société) -->
                            <div id="registre-rcc-container" class="hidden">
                                <label for="registre_rcc" class="block text-sm font-medium text-gray-700">Registre RCC <span class="text-red-500">*</span></label>
                                <input type="text" id="registre_rcc" name="registre_rcc" value="<?php echo isset($_POST['registre_rcc']) ? htmlspecialchars($_POST['registre_rcc']) : ''; ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                            
                            <!-- Délai de paiement (pour société) -->
                            <div id="delai-paiement-container" class="hidden">
                                <label for="delai_paiement_select" class="block text-sm font-medium text-gray-700">Délai de paiement</label>
                                <div class="flex flex-col space-y-2">
                                    <select id="delai_paiement_select" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                                        <option value="">Sélectionnez un délai ou saisissez une valeur personnalisée</option>
                                        <option value="14">2 semaines (14 jours)</option>
                                        <option value="30">1 mois (30 jours)</option>
                                        <option value="60">2 mois (60 jours)</option>
                                        <option value="custom">Personnalisé</option>
                                    </select>
                                    
                                    <div id="custom_delai_container" class="hidden flex items-center">
                                        <input type="number" id="delai_paiement" name="delai_paiement" min="0" max="60" value="<?php echo isset($_POST['delai_paiement']) ? htmlspecialchars($_POST['delai_paiement']) : '0'; ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                                        <span class="ml-2 text-sm text-gray-500">jours (max 60)</span>
                                    </div>
                                </div>
                                <p class="mt-1 text-sm text-gray-500">Nombre de jours accordés pour le paiement des factures.</p>
                            </div>

                            <!-- Email -->
                            <div>
                                <label for="email" class="block text-sm font-medium text-gray-700">Email <span class="text-red-500">*</span></label>
                                <input type="email" id="email" name="email" value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" required>
                            </div>

                            <!-- Téléphone -->
                            <div>
                                <label for="telephone" class="block text-sm font-medium text-gray-700">Téléphone <span class="text-red-500">*</span></label>
                                <input type="text" id="telephone" name="telephone" value="<?php echo isset($_POST['telephone']) ? htmlspecialchars($_POST['telephone']) : ''; ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" required>
                            </div>

                            <!-- Adresse -->
                            <div class="md:col-span-2">
                                <label for="adresse" class="block text-sm font-medium text-gray-700">Adresse</label>
                                <input type="text" id="adresse" name="adresse" value="<?php echo isset($_POST['adresse']) ? htmlspecialchars($_POST['adresse']) : ''; ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <!-- Code Postal -->
                            <div>
                                <label for="code_postal" class="block text-sm font-medium text-gray-700">Code Postal</label>
                                <input type="text" id="code_postal" name="code_postal" value="<?php echo isset($_POST['code_postal']) ? htmlspecialchars($_POST['code_postal']) : ''; ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                            </div>

                            <!-- Ville -->
                            <div>
                                <label for="ville" class="block text-sm font-medium text-gray-700">Ville</label>
                                <input type="text" id="ville" name="ville" value="<?php echo isset($_POST['ville']) ? htmlspecialchars($_POST['ville']) : ''; ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                            
                            <!-- Notes -->
                            <div class="md:col-span-2">
                                <label for="notes" class="block text-sm font-medium text-gray-700">Notes</label>
                                <input type="text" id="notes" name="notes" value="<?php echo isset($_POST['notes']) ? htmlspecialchars($_POST['notes']) : ''; ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                        </div>

                        <!-- Boutons -->
                        <div class="mt-8 flex justify-end space-x-3">
                            <a href="index.php" class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50">Annuler</a>
                            <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">Créer</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Script pour champs dynamiques -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const typeClientSelect = document.getElementById('type_client');
    const prenomContainer = document.getElementById('prenom-container');
    const nomContainer = document.getElementById('nom-container');
    const raisonSocialeContainer = document.getElementById('raison-sociale-container');
    const registreRCCContainer = document.getElementById('registre-rcc-container');
    const delaiPaiementContainer = document.getElementById('delai-paiement-container');
    
    // Nouveaux éléments pour le délai de paiement
    const delaiPaiementSelect = document.getElementById('delai_paiement_select');
    const customDelaiContainer = document.getElementById('custom_delai_container');
    const delaiPaiementInput = document.getElementById('delai_paiement');

    function updateFields() {
        const selectedType = typeClientSelect.value;

        if (selectedType === 'client') {
            prenomContainer.classList.remove('hidden');
            nomContainer.classList.remove('hidden');
            raisonSocialeContainer.classList.add('hidden');
            registreRCCContainer.classList.add('hidden');
            delaiPaiementContainer.classList.add('hidden');
        } else if (selectedType === 'societe') {
            prenomContainer.classList.add('hidden');
            nomContainer.classList.add('hidden');
            raisonSocialeContainer.classList.remove('hidden');
            registreRCCContainer.classList.remove('hidden');
            delaiPaiementContainer.classList.remove('hidden');
        } else {
            prenomContainer.classList.add('hidden');
            nomContainer.classList.add('hidden');
            raisonSocialeContainer.classList.add('hidden');
            registreRCCContainer.classList.add('hidden');
            delaiPaiementContainer.classList.add('hidden');
        }
    }

    // Gestion du délai de paiement
    delaiPaiementSelect.addEventListener('change', function() {
        const selectedValue = this.value;
        
        if (selectedValue === 'custom') {
            // Afficher le champ personnalisé
            customDelaiContainer.classList.remove('hidden');
            delaiPaiementInput.focus();
        } else {
            // Masquer le champ personnalisé et définir la valeur sélectionnée
            customDelaiContainer.classList.add('hidden');
            delaiPaiementInput.value = selectedValue;
        }
    });

    // Validation du délai de paiement
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

    typeClientSelect.addEventListener('change', updateFields);
    updateFields(); // Initialisation au chargement
});
</script>

<?php include $root_path . '/includes/footer.php'; ?>
