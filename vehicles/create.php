
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

// Récupérer la liste des clients pour le formulaire
$database = new Database();
$db = $database->getConnection();
$query = "SELECT  cl.id as client_id,
                     CASE 
                        WHEN cl.type_client_id = 1 THEN CONCAT(cl.prenom, ' ', cl.nom)
                        ELSE CONCAT(cl.raison_sociale)
                     END AS Nom_Client
                     from clients cl;"
                     ;
$stmt = $db->prepare($query);
$stmt->execute();
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Traitement du formulaire
$errors = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validation des données
    if (empty($_POST['immatriculation'])) {
        $errors['immatriculation'] = 'L\'immatriculation est requise';
    }
    
    if (empty($_POST['client_id'])) {
        $errors['client_id'] = 'Le client est requis';
    }
    
    if (empty($_POST['marque'])) {
        $errors['marque'] = 'La marque est requise';
    }
    
    if (empty($_POST['modele'])) {
        $errors['modele'] = 'Le modèle est requis';
    }
    
    if (empty($_POST['annee'])) {
        $errors['annee'] = 'L\'année est requise';
    }
    
    if (empty($_POST['kilometrage'])) {
        $errors['kilometrage'] = 'Le kilométrage est requis';
    }
   
    // Si aucune erreur, créer le véhicule
    if (empty($errors)) {
        try {
            // Préparer la requête d'insertion
            $query = "INSERT INTO vehicules (immatriculation, client_id, marque, modele, annee, kilometrage, couleur, carburant, puissance, date_mise_circulation, date_derniere_revision, date_prochain_ct, statut, notes,date_creation) 
                     VALUES (:immatriculation, :client_id, :marque, :modele, :annee, :kilometrage, :couleur, :carburant, :puissance, :date_mise_circulation, :date_derniere_revision, :date_prochain_ct, :statut, :notes,:date_creation)";
            
            $stmt = $db->prepare($query);
            
            // Binder les paramètres
            $stmt->bindParam(':immatriculation', $_POST['immatriculation']);
            $stmt->bindParam(':client_id', $_POST['client_id']);
            $stmt->bindParam(':marque', $_POST['marque']);
            $stmt->bindParam(':modele', $_POST['modele']);
            $stmt->bindParam(':annee', $_POST['annee']);
            $stmt->bindParam(':kilometrage', $_POST['kilometrage']);
            $stmt->bindParam(':couleur', $_POST['couleur']);
            $stmt->bindParam(':carburant', $_POST['carburant']);
            $stmt->bindParam(':puissance', $_POST['puissance']);
            $date_mise_circulation = date('Y-m-d', strtotime($_POST['date_mise_circulation']));
            $stmt->bindParam(':date_mise_circulation', $date_mise_circulation);
            $date_derniere_revision = date('Y-m-d', strtotime($_POST['date_derniere_revision']));
            $stmt->bindParam(':date_derniere_revision', $date_derniere_revision);            
            $date_prochain_ct = date('Y-m-d', strtotime($_POST['date_prochain_ct']));
            $stmt->bindParam(':date_prochain_ct', $date_prochain_ct);
            $stmt->bindParam(':statut', $_POST['statut']);
            $stmt->bindParam(':notes', $_POST['notes']);
            $date_creation = date('Y-m-d');
            $stmt->bindParam(':date_creation', $date_creation);
            // Exécuter la requête
            $stmt->execute();
            
            // Récupérer l'ID du véhicule nouvellement créé
            $vehicule_id = $db->lastInsertId();
            
            $vehicule = [
                'id' => $vehicule_id,
                'immatriculation' => $_POST['immatriculation'],
                'client_id' => $_POST['client_id'],
                'marque' => $_POST['marque'],
                'modele' => $_POST['modele'],
                'annee' => $_POST['annee'],
                'kilometrage' => $_POST['kilometrage']
            ];
            
            $success = true;
            
            // Option de redirection:
            header('Location: view.php');
            exit;
        } catch (PDOException $e) {
            // Gérer les erreurs de base de données
            $errors['database'] = 'Erreur lors de la création du véhicule: ' . $e->getMessage();
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
        <!-- Top header -->
        <div class="bg-white shadow-sm">
            <div class="container mx-auto px-6 py-4 flex justify-between items-center">
                <h1 class="text-2xl font-semibold text-gray-800">Ajouter un véhicule</h1>
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <span class="text-gray-700"><?php echo isset($currentUser['name']) ? htmlspecialchars($currentUser['name']) : 'Utilisateur'; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Create Vehicle Form -->
        <div class="container mx-auto px-6 py-8">
            <!-- Breadcrumb -->
            <nav class="mb-6" aria-label="Breadcrumb">
                <ol class="flex text-sm text-gray-600">
                    <li>
                        <a href="<?php echo $root_path; ?>/dashboard.php" class="hover:text-indigo-600">Tableau de bord</a>
                    </li>
                    <li class="mx-2">/</li>
                    <li>
                        <a href="view.php" class="hover:text-indigo-600">Véhicules</a>
                    </li>
                    <li class="mx-2">/</li>
                    <li class="text-gray-800 font-medium">Ajouter</li>
                </ol>
            </nav>
            
            <!-- Success Message -->
            <?php if ($success): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-6 rounded" role="alert">
                    <p class="font-bold">Succès!</p>
                    <p>Le véhicule a été créé avec succès.</p>
                    <div class="mt-2">
                        <a href="view.php" class="text-green-600 hover:underline font-medium">Retourner à la liste des véhicules</a>
                        ou
                        <a href="create.php" class="text-green-600 hover:underline font-medium">Ajouter un autre véhicule</a>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Form Card -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="p-6">
                    <h2 class="text-lg font-semibold text-gray-800 mb-6">Informations du véhicule</h2>
                    
                    <form action="create.php" method="POST" data-validate="true" id="create-vehicle-form">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Immatriculation -->
                            <div>
                                <label for="immatriculation" class="block text-sm font-medium text-gray-700 mb-1">Immatriculation <span class="text-red-500">*</span></label>
                                <input type="text" id="immatriculation" name="immatriculation" value="<?php echo isset($_POST['immatriculation']) ? htmlspecialchars($_POST['immatriculation']) : ''; ?>" class="w-full px-4 py-2 border <?php echo isset($errors['immatriculation']) ? 'border-red-500' : 'border-gray-300'; ?> rounded-lg focus:ring-indigo-500 focus:border-indigo-500" placeholder="AB-123-CD" required>
                                <?php if (isset($errors['immatriculation'])): ?>
                                    <p class="text-red-500 text-xs mt-1"><?php echo $errors['immatriculation']; ?></p>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Client -->
                            <div>
                                <label for="client_id" class="block text-sm font-medium text-gray-700 mb-1">Client <span class="text-red-500">*</span></label>
                                <select id="client_id" name="client_id" class="w-full px-4 py-2 border <?php echo isset($errors['client_id']) ? 'border-red-500' : 'border-gray-300'; ?> rounded-lg focus:ring-indigo-500 focus:border-indigo-500" required>
                                    <option value="">Sélectionner un client</option>
                                    <?php foreach ($clients as $client): ?>
                                        <option value="<?php echo $client['client_id']; ?>" <?php echo (isset($_POST['client_id']) && $_POST['client_id'] == $client['client_id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($client['Nom_Client']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if (isset($errors['client_id'])): ?>
                                    <p class="text-red-500 text-xs mt-1"><?php echo $errors['client_id']; ?></p>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Marque -->
                            <div>
                                <label for="marque" class="block text-sm font-medium text-gray-700 mb-1">Marque <span class="text-red-500">*</span></label>
                                <select id="marque" name="marque" class="w-full px-4 py-2 border <?php echo isset($errors['marque']) ? 'border-red-500' : 'border-gray-300'; ?> rounded-lg focus:ring-indigo-500 focus:border-indigo-500" required>
                                    <option value="">:: MARQUE ::</option>
                                    <option value="abarth" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'abarth') ? 'selected' : ''; ?>>ABARTH</option>
                                    <option value="alfa_romeo" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'alfa_romeo') ? 'selected' : ''; ?>>ALFA ROMEO</option>
                                    <option value="audi" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'audi') ? 'selected' : ''; ?>>AUDI</option>
                                    <option value="bmw" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'bmw') ? 'selected' : ''; ?>>BMW</option>
                                    <option value="byd" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'byd') ? 'selected' : ''; ?>>BYD</option>
                                    <option value="changan" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'changan') ? 'selected' : ''; ?>>CHANGAN</option>
                                    <option value="chery" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'chery') ? 'selected' : ''; ?>>CHERY</option>
                                    <option value="citroen" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'citroen') ? 'selected' : ''; ?>>CITROEN</option>
                                    <option value="cupra" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'cupra') ? 'selected' : ''; ?>>CUPRA</option>
                                    <option value="dacia" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'dacia') ? 'selected' : ''; ?>>DACIA</option>
                                    <option value="dfsk" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'dfsk') ? 'selected' : ''; ?>>DFSK</option>
                                    <option value="ds" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'ds') ? 'selected' : ''; ?>>DS</option>
                                    <option value="fiat" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'fiat') ? 'selected' : ''; ?>>FIAT</option>
                                    <option value="ford" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'ford') ? 'selected' : ''; ?>>FORD</option>
                                    <option value="geely" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'geely') ? 'selected' : ''; ?>>GEELY</option>
                                    <option value="gwm" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'gwm') ? 'selected' : ''; ?>>GWM</option>
                                    <option value="honda" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'honda') ? 'selected' : ''; ?>>HONDA</option>
                                    <option value="hyundai" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'hyundai') ? 'selected' : ''; ?>>HYUNDAI</option>
                                    <option value="jaecoo" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'jaecoo') ? 'selected' : ''; ?>>JAECOO</option>
                                    <option value="jaguar" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'jaguar') ? 'selected' : ''; ?>>JAGUAR</option>
                                    <option value="jeep" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'jeep') ? 'selected' : ''; ?>>JEEP</option>
                                    <option value="kia" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'kia') ? 'selected' : ''; ?>>KIA</option>
                                    <option value="land_rover" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'land_rover') ? 'selected' : ''; ?>>LAND ROVER</option>
                                    <option value="lexus" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'lexus') ? 'selected' : ''; ?>>LEXUS</option>
                                    <option value="mahindra" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'mahindra') ? 'selected' : ''; ?>>MAHINDRA</option>
                                    <option value="maserati" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'maserati') ? 'selected' : ''; ?>>MASERATI</option>
                                    <option value="mazda" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'mazda') ? 'selected' : ''; ?>>MAZDA</option>
                                    <option value="mercedes" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'mercedes') ? 'selected' : ''; ?>>MERCEDES</option>
                                    <option value="mg" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'mg') ? 'selected' : ''; ?>>MG</option>
                                    <option value="mini" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'mini') ? 'selected' : ''; ?>>MINI</option>
                                    <option value="mitsubishi" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'mitsubishi') ? 'selected' : ''; ?>>MITSUBISHI</option>
                                    <option value="nissan" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'nissan') ? 'selected' : ''; ?>>NISSAN</option>
                                    <option value="omoda" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'omoda') ? 'selected' : ''; ?>>OMODA</option>
                                    <option value="opel" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'opel') ? 'selected' : ''; ?>>OPEL</option>
                                    <option value="peugeot" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'peugeot') ? 'selected' : ''; ?>>PEUGEOT</option>
                                    <option value="porsche" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'porsche') ? 'selected' : ''; ?>>PORSCHE</option>
                                    <option value="renault" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'renault') ? 'selected' : ''; ?>>RENAULT</option>
                                    <option value="seat" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'seat') ? 'selected' : ''; ?>>SEAT</option>
                                    <option value="seres" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'seres') ? 'selected' : ''; ?>>SERES</option>
                                    <option value="skoda" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'skoda') ? 'selected' : ''; ?>>SKODA</option>
                                    <option value="suzuki" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'suzuki') ? 'selected' : ''; ?>>SUZUKI</option>
                                    <option value="toyota" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'toyota') ? 'selected' : ''; ?>>TOYOTA</option>
                                    <option value="volkswagen" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'volkswagen') ? 'selected' : ''; ?>>VOLKSWAGEN</option>
                                    <option value="volvo" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'volvo') ? 'selected' : ''; ?>>VOLVO</option>
                                    <option value="autre" <?php echo (isset($_POST['marque']) && $_POST['marque'] == 'autre') ? 'selected' : ''; ?>>Autre</option>
                                </select>
                                <?php if (isset($errors['marque'])): ?>
                                    <p class="text-red-500 text-xs mt-1"><?php echo $errors['marque']; ?></p>
                                <?php endif; ?>
                            </div>

                            
                            <!-- Modèle -->
                            <div>
                                <label for="modele" class="block text-sm font-medium text-gray-700 mb-1">Modèle <span class="text-red-500">*</span></label>
                                <input type="text" id="modele" name="modele" value="<?php echo isset($_POST['modele']) ? htmlspecialchars($_POST['modele']) : ''; ?>" class="w-full px-4 py-2 border <?php echo isset($errors['modele']) ? 'border-red-500' : 'border-gray-300'; ?> rounded-lg focus:ring-indigo-500 focus:border-indigo-500" placeholder="Clio, 308, etc." required>
                                <?php if (isset($errors['modele'])): ?>
                                    <p class="text-red-500 text-xs mt-1"><?php echo $errors['modele']; ?></p>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Année -->
                            <div>
                                <label for="annee" class="block text-sm font-medium text-gray-700 mb-1">Année <span class="text-red-500">*</span></label>
                                <input type="number" id="annee" name="annee" value="<?php echo isset($_POST['annee']) ? htmlspecialchars($_POST['annee']) : date('Y'); ?>" class="w-full px-4 py-2 border <?php echo isset($errors['annee']) ? 'border-red-500' : 'border-gray-300'; ?> rounded-lg focus:ring-indigo-500 focus:border-indigo-500" min="1900" max="<?php echo date('Y') + 1; ?>" required>
                                <?php if (isset($errors['annee'])): ?>
                                    <p class="text-red-500 text-xs mt-1"><?php echo $errors['annee']; ?></p>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Kilométrage -->
                            <div>
                                <label for="kilometrage" class="block text-sm font-medium text-gray-700 mb-1">Kilométrage <span class="text-red-500">*</span></label>
                                <input type="number" id="kilometrage" name="kilometrage" value="<?php echo isset($_POST['kilometrage']) ? htmlspecialchars($_POST['kilometrage']) : '0'; ?>" class="w-full px-4 py-2 border <?php echo isset($errors['kilometrage']) ? 'border-red-500' : 'border-gray-300'; ?> rounded-lg focus:ring-indigo-500 focus:border-indigo-500" min="0" required>
                                <?php if (isset($errors['kilometrage'])): ?>
                                    <p class="text-red-500 text-xs mt-1"><?php echo $errors['kilometrage']; ?></p>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Couleur -->
                            <div>
                                <label for="couleur" class="block text-sm font-medium text-gray-700 mb-1">Couleur</label>
                                <input type="text" id="couleur" name="couleur" value="<?php echo isset($_POST['couleur']) ? htmlspecialchars($_POST['couleur']) : ''; ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" placeholder="Noir, Blanc, etc.">
                            </div>
                            
                            <!-- Carburant -->
                            <div>
                                <label for="carburant" class="block text-sm font-medium text-gray-700 mb-1">Type de carburant</label>
                                <select id="carburant" name="carburant" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                                    <option value="">Sélectionner un type</option>
                                    <option value="essence" <?php echo (isset($_POST['carburant']) && $_POST['carburant'] == 'essence') ? 'selected' : ''; ?>>Essence</option>
                                    <option value="diesel" <?php echo (isset($_POST['carburant']) && $_POST['carburant'] == 'diesel') ? 'selected' : ''; ?>>Diesel</option>
                                    <option value="hybride" <?php echo (isset($_POST['carburant']) && $_POST['carburant'] == 'hybride') ? 'selected' : ''; ?>>Hybride</option>
                                    <option value="electrique" <?php echo (isset($_POST['carburant']) && $_POST['carburant'] == 'electrique') ? 'selected' : ''; ?>>Électrique</option>
                                    <option value="gpl" <?php echo (isset($_POST['carburant']) && $_POST['carburant'] == 'gpl') ? 'selected' : ''; ?>>GPL</option>
                                </select>
                            </div>
                            
                            <!-- Puissance -->
                            <div>
                                <label for="puissance" class="block text-sm font-medium text-gray-700 mb-1">Puissance fiscale (CV)</label>
                                <input type="number" id="puissance" name="puissance" value="<?php echo isset($_POST['puissance']) ? htmlspecialchars($_POST['puissance']) : ''; ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" placeholder="5" min="1">
                            </div>
                            
                            <!-- Date d'achat -->
                            <div>
                                <label for="date_mise_circulation" class="block text-sm font-medium text-gray-700 mb-1">Date d'achat</label>
                                <input type="date" id="date_mise_circulation" name="date_mise_circulation" value="<?php echo isset($_POST['date_mise_circulation']) ? htmlspecialchars($_POST['date_mise_circulation']) : ''; ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                            
                            <!-- Date dernière révision -->
                            <div>
                                <label for="date_derniere_revision" class="block text-sm font-medium text-gray-700 mb-1">Date dernière révision</label>
                                <input type="date" id="date_derniere_revision" name="date_derniere_revision" value="<?php echo isset($_POST['date_derniere_revision']) ? htmlspecialchars($_POST['date_derniere_revision']) : ''; ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                            
                            <!-- Date prochain contrôle technique -->
                            <div>
                                <label for="date_prochain_ct" class="block text-sm font-medium text-gray-700 mb-1">Date prochain contrôle technique</label>
                                <input type="date" id="date_prochain_ct" name="date_prochain_ct" value="<?php echo isset($_POST['date_prochain_ct']) ? htmlspecialchars($_POST['date_prochain_ct']) : ''; ?>" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                            </div>
                            
                            <!-- Statut -->
                            <div>
                                <label for="statut" class="block text-sm font-medium text-gray-700 mb-1">Statut</label>
                                <select id="statut" name="statut" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                                    <option value="actif" <?php echo (!isset($_POST['statut']) || $_POST['statut'] == 'actif') ? 'selected' : ''; ?>>Actif</option>
                                    <option value="maintenance" <?php echo (isset($_POST['statut']) && $_POST['statut'] == 'maintenance') ? 'selected' : ''; ?>>En maintenance</option>
                                    <option value="inactif" <?php echo (isset($_POST['statut']) && $_POST['statut'] == 'inactif') ? 'selected' : ''; ?>>Inactif</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Notes -->
                        <div class="mt-6">
                            <label for="notes" class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                            <textarea id="notes" name="notes" rows="3" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" placeholder="Informations complémentaires sur le véhicule..."><?php echo isset($_POST['notes']) ? htmlspecialchars($_POST['notes']) : ''; ?></textarea>
                        </div>
                        
                        <!-- Form Actions -->
                        <div class="mt-8 flex justify-end space-x-3">
                            <a href="view.php" class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition">
                                Annuler
                            </a>
                            <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition">
                                Créer le véhicule
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Tips Card -->
            <div class="mt-6 bg-blue-50 rounded-lg p-4 border border-blue-100">
                <div class="flex items-start">
                    <div class="mr-3">
                        <svg class="w-6 h-6 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-sm font-medium text-blue-800">Conseils pour l'ajout de véhicules</h3>
                        <ul class="mt-2 text-sm text-blue-700 list-disc list-inside">
                            <li>Vérifiez que l'immatriculation est correctement formatée</li>
                            <li>Assurez-vous que le client associé existe bien dans la base de données</li>
                            <li>Les dates de révision et de contrôle technique permettent de planifier les entretiens</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include $root_path . '/includes/footer.php'; ?>
