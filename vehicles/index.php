<?php
// Démarrer la session d'abord
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
$database = new Database();
$db = $database->getConnection();

// Vérifier si l'utilisateur est connecté, sinon rediriger vers la page de connexion
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Récupérer les informations de l'utilisateur actuel
// Utilisateur temporaire pour éviter l'erreur
$currentUser = [];
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    $stmt = $db->prepare("SELECT nom, prenom, role FROM users WHERE id = ?");
    if ($stmt) {
        $stmt->bindParam(1, $user_id, PDO::PARAM_INT);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user) {
            $currentUser = [
                'name' => $user['prenom'] . ' ' . $user['nom'],
                'role' => $user['role']
            ];
        }
    }
}

// Inclure l'en-tête
include '../includes/header.php';

// Données de test pour les véhicules
$vehicles = [];
try {
    $query = "SELECT v.id, v.immatriculation, v.marque, v.modele, v.annee, CONCAT(c.nom, ' ', c.prenom) AS client, v.kilometrage, v.statut FROM vehicules v INNER JOIN clients c ON v.client_id = c.id";

$stmt = $db->prepare($query);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Client non trouvé, rediriger vers la liste
        header('Location: index.php');
        exit;
    }
} catch (PDOException $e) {
    $errors['database'] = 'Erreur lors de la récupération du client: ' . $e->getMessage();
}


// Données de test pour les clients

$clients = [];
try {
    $query = "SELECT id, nom, prenom FROM clients";
    $stmt = $db->prepare($query);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // Client non trouvé, rediriger vers la liste
        header('Location: index.php');
        exit;
    }
} catch (PDOException $e) {
    $errors['database'] = 'Erreur lors de la récupération du client: ' . $e->getMessage();
}

?>

<div class="flex h-screen bg-gray-100">
    <!-- Sidebar -->
    <?php include '../includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 overflow-y-auto">
        <!-- Top header -->
        <div class="bg-white shadow-sm">
            <div class="container mx-auto px-6 py-4 flex justify-between items-center">
                <h1 class="text-2xl font-semibold text-gray-800">Gestion des Véhicules</h1>
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <span class="text-gray-700"><?php echo isset($currentUser['name']) ? htmlspecialchars($currentUser['name']) : 'Utilisateur'; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="container mx-auto px-6 py-8">
            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- Total Vehicles Card -->
                <div class="bg-white p-6 rounded-lg shadow-md transition duration-300 hover:shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-700">Total Véhicules</h3>
                            <p class="text-3xl font-bold text-gray-800 mt-2">
                                <?php echo count($vehicles); ?>
                            </p>
                        </div>
                        <div class="bg-blue-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="#" class="text-blue-500 hover:text-blue-700 text-sm font-semibold">Voir tous les véhicules →</a>
                    </div>
                </div>

                <!-- Maintenance Vehicles Card -->
                <div class="bg-white p-6 rounded-lg shadow-md transition duration-300 hover:shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-700">En maintenance</h3>
                            <p class="text-3xl font-bold text-gray-800 mt-2">
                                <?php 
                                    $maintenanceCount = 0;
                                    foreach ($vehicles as $vehicle) {
                                        if ($vehicle['statut'] === 'maintenance') {
                                            $maintenanceCount++;
                                        }
                                    }
                                    echo $maintenanceCount;
                                ?>
                            </p>
                        </div>
                        <div class="bg-yellow-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="#" class="text-yellow-500 hover:text-yellow-700 text-sm font-semibold">Voir les détails →</a>
                    </div>
                </div>

                <!-- Technical Control Card -->
                <div class="bg-white p-6 rounded-lg shadow-md transition duration-300 hover:shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-700">Contrôles techniques</h3>
                            <p class="text-3xl font-bold text-gray-800 mt-2">5</p>
                        </div>
                        <div class="bg-red-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="#" class="text-red-500 hover:text-red-700 text-sm font-semibold">Voir les échéances →</a>
                    </div>
                </div>

                <!-- Average Mileage Card -->
                <div class="bg-white p-6 rounded-lg shadow-md transition duration-300 hover:shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-700">Kilométrage moyen</h3>
                            <p class="text-3xl font-bold text-gray-800 mt-2">65k</p>
                        </div>
                        <div class="bg-green-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                            </svg>
                        </div>
                    </div>
                    <div class="mt-4">
                        <a href="#" class="text-green-500 hover:text-green-700 text-sm font-semibold">Voir les statistiques →</a>
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
                <!-- Quick Actions Card -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-xl font-semibold text-gray-800 mb-4">Actions rapides</h3>
                    <div class="space-y-4">
                        <a href="create.php" class="flex items-center p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition duration-200">
                            <div class="bg-blue-100 p-2 rounded-full mr-4">
                                <svg class="w-6 h-6 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                </svg>
                            </div>
                            <div>
                                <h4 class="font-medium text-gray-800">Ajouter un véhicule</h4>
                                <p class="text-sm text-gray-600">Enregistrer un nouveau véhicule</p>
                            </div>
                        </a>
                        <a href="#" class="flex items-center p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition duration-200">
                            <div class="bg-green-100 p-2 rounded-full mr-4">
                                <svg class="w-6 h-6 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                                </svg>
                            </div>
                            <div>
                                <h4 class="font-medium text-gray-800">Exporter les données</h4>
                                <p class="text-sm text-gray-600">Exporter en CSV ou Excel</p>
                            </div>
                        </a>
                        <a href="#" class="flex items-center p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition duration-200">
                            <div class="bg-purple-100 p-2 rounded-full mr-4">
                                <svg class="w-6 h-6 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                                </svg>
                            </div>
                            <div>
                                <h4 class="font-medium text-gray-800">Imprimer la liste</h4>
                                <p class="text-sm text-gray-600">Générer un rapport imprimable</p>
                            </div>
                        </a>
                    </div>
                </div>

                <!-- Maintenance Alerts Card -->
                <div class="bg-white rounded-lg shadow-md p-6 col-span-2">
                    <h3 class="text-xl font-semibold text-gray-800 mb-4">Alertes maintenance</h3>
                    <div class="space-y-4">
                        <div class="flex items-center p-3 bg-red-50 rounded-lg">
                            <div class="bg-red-100 p-2 rounded-full mr-4">
                                <svg class="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <h4 class="font-medium text-gray-800">Contrôle technique à prévoir</h4>
                                <p class="text-sm text-gray-600">5 véhicules ont un contrôle technique à prévoir dans les 30 jours</p>
                            </div>
                            <a href="#" class="px-3 py-1 bg-red-100 text-red-700 rounded-md text-sm font-medium hover:bg-red-200 transition duration-200">Voir</a>
                        </div>
                        <div class="flex items-center p-3 bg-yellow-50 rounded-lg">
                            <div class="bg-yellow-100 p-2 rounded-full mr-4">
                                <svg class="w-6 h-6 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <h4 class="font-medium text-gray-800">Vidanges à effectuer</h4>
                                <p class="text-sm text-gray-600">3 véhicules ont dépassé le kilométrage recommandé pour la vidange</p>
                            </div>
                            <a href="#" class="px-3 py-1 bg-yellow-100 text-yellow-700 rounded-md text-sm font-medium hover:bg-yellow-200 transition duration-200">Voir</a>
                        </div>
                        <div class="flex items-center p-3 bg-blue-50 rounded-lg">
                            <div class="bg-blue-100 p-2 rounded-full mr-4">
                                <svg class="w-6 h-6 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                                </svg>
                            </div>
                            <div class="flex-1">
                                <h4 class="font-medium text-gray-800">Batteries à vérifier</h4>
                                <p class="text-sm text-gray-600">2 véhicules ont des batteries de plus de 3 ans</p>
                            </div>
                            <a href="#" class="px-3 py-1 bg-blue-100 text-blue-700 rounded-md text-sm font-medium hover:bg-blue-200 transition duration-200">Voir</a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Vehicles List -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-semibold text-gray-800">Liste des véhicules</h3>
                    <a href="create.php" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Ajouter un véhicule
                    </a>
                </div>

                <!-- Search and Filter -->
                <div class="flex flex-col md:flex-row gap-4 mb-6">
                    <div class="relative flex-1">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                        <input type="text" class="pl-10 pr-4 py-2 w-full border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Rechercher un véhicule...">
                    </div>
                    <div class="flex gap-4">
                        <select class="px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option>Tous les statuts</option>
                            <option>Actif</option>
                            <option>En maintenance</option>
                            <option>Inactif</option>
                        </select>
                        <select class="px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        <option value="abarth" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'abarth') ? 'selected' : ''; ?>>ABARTH</option>
        <option value="alfa_romeo" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'alfa_romeo') ? 'selected' : ''; ?>>ALFA ROMEO</option>
        <option value="audi" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'audi') ? 'selected' : ''; ?>>AUDI</option>
        <option value="bmw" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'bmw') ? 'selected' : ''; ?>>BMW</option>
        <option value="byd" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'byd') ? 'selected' : ''; ?>>BYD</option>
        <option value="changan" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'changan') ? 'selected' : ''; ?>>CHANGAN</option>
        <option value="chery" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'chery') ? 'selected' : ''; ?>>CHERY</option>
        <option value="citroen" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'citroen') ? 'selected' : ''; ?>>CITROEN</option>
        <option value="cupra" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'cupra') ? 'selected' : ''; ?>>CUPRA</option>
        <option value="dacia" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'dacia') ? 'selected' : ''; ?>>DACIA</option>
        <option value="dfsk" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'dfsk') ? 'selected' : ''; ?>>DFSK</option>
        <option value="ds" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'ds') ? 'selected' : ''; ?>>DS</option>
        <option value="fiat" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'fiat') ? 'selected' : ''; ?>>FIAT</option>
        <option value="ford" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'ford') ? 'selected' : ''; ?>>FORD</option>
        <option value="geely" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'geely') ? 'selected' : ''; ?>>GEELY</option>
        <option value="gwm" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'gwm') ? 'selected' : ''; ?>>GWM</option>
        <option value="honda" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'honda') ? 'selected' : ''; ?>>HONDA</option>
        <option value="hyundai" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'hyundai') ? 'selected' : ''; ?>>HYUNDAI</option>
        <option value="jaecoo" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'jaecoo') ? 'selected' : ''; ?>>JAECOO</option>
        <option value="jaguar" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'jaguar') ? 'selected' : ''; ?>>JAGUAR</option>
        <option value="jeep" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'jeep') ? 'selected' : ''; ?>>JEEP</option>
        <option value="kia" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'kia') ? 'selected' : ''; ?>>KIA</option>
        <option value="land_rover" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'land_rover') ? 'selected' : ''; ?>>LAND ROVER</option>
        <option value="lexus" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'lexus') ? 'selected' : ''; ?>>LEXUS</option>
        <option value="mahindra" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'mahindra') ? 'selected' : ''; ?>>MAHINDRA</option>
        <option value="maserati" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'maserati') ? 'selected' : ''; ?>>MASERATI</option>
        <option value="mazda" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'mazda') ? 'selected' : ''; ?>>MAZDA</option>
        <option value="mercedes" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'mercedes') ? 'selected' : ''; ?>>MERCEDES</option>
        <option value="mg" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'mg') ? 'selected' : ''; ?>>MG</option>
        <option value="mini" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'mini') ? 'selected' : ''; ?>>MINI</option>
        <option value="mitsubishi" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'mitsubishi') ? 'selected' : ''; ?>>MITSUBISHI</option>
        <option value="nissan" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'nissan') ? 'selected' : ''; ?>>NISSAN</option>
        <option value="omoda" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'omoda') ? 'selected' : ''; ?>>OMODA</option>
        <option value="opel" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'opel') ? 'selected' : ''; ?>>OPEL</option>
        <option value="peugeot" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'peugeot') ? 'selected' : ''; ?>>PEUGEOT</option>
        <option value="porsche" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'porsche') ? 'selected' : ''; ?>>PORSCHE</option>
        <option value="renault" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'renault') ? 'selected' : ''; ?>>RENAULT</option>
        <option value="seat" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'seat') ? 'selected' : ''; ?>>SEAT</option>
        <option value="seres" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'seres') ? 'selected' : ''; ?>>SERES</option>
        <option value="skoda" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'skoda') ? 'selected' : ''; ?>>SKODA</option>
        <option value="suzuki" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'suzuki') ? 'selected' : ''; ?>>SUZUKI</option>
        <option value="toyota" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'toyota') ? 'selected' : ''; ?>>TOYOTA</option>
        <option value="volkswagen" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'volkswagen') ? 'selected' : ''; ?>>VOLKSWAGEN</option>
        <option value="volvo" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'volvo') ? 'selected' : ''; ?>>VOLVO</option>
        <option value="autre" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'autre') ? 'selected' : ''; ?>>Autre</option>
    </select>
                        <!-- <select class="px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option>Toutes les marques</option>
                            <option>Renault</option>
                            <option>Peugeot</option>
                            <option>Citroën</option>
                            <option>Volkswagen</option>
                            <option>BMW</option>
                        </select> -->
                    </div>
                </div>

                <!-- Table -->
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class=" hidden px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Immatriculation</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Marque</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Modèle</th>
                                <th scope="col" class="hidden px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Année</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Client</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kilométrage</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($vehicles as $vehicle): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="hidden px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $vehicle['id']; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo $vehicle['immatriculation']; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $vehicle['marque']; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $vehicle['modele']; ?></td>
                                    <td class="hidden px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $vehicle['annee']; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $vehicle['client']; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $vehicle['kilometrage']; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $statusClass = '';
                                        $statusText = '';
                                        
                                        switch ($vehicle['statut']) {
                                            case 'actif':
                                                $statusClass = 'bg-green-100 text-green-800';
                                                $statusText = 'Actif';
                                                break;
                                            case 'maintenance':
                                                $statusClass = 'bg-yellow-100 text-yellow-800';
                                                $statusText = 'En maintenance';
                                                break;
                                            case 'inactif':
                                                $statusClass = 'bg-red-100 text-red-800';
                                                $statusText = 'Inactif';
                                                break;
                                        }
                                        ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $statusClass; ?>">
                                            <?php echo $statusText; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <button onclick="viewVehicle(<?php echo $vehicle['id']; ?>)" class="text-blue-600 hover:text-blue-900">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                            </svg>
                                            </button>
                                            <button onclick="editVehicle(<?php echo $vehicle['id']; ?>)" class="text-indigo-600 hover:text-indigo-900">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                </svg>
                                            </button>
                                            <button onclick="deleteVehicle(<?php echo $vehicle['id']; ?>, event)" class="text-red-600 hover:text-red-900">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <div class="flex justify-between items-center mt-6">
                    <div class="text-sm text-gray-500">
                        Affichage de <span class="font-medium">1</span> à <span class="font-medium">7</span> sur <span class="font-medium">7</span> résultats
                    </div>
                    <div class="flex space-x-1">
                        <button class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                            Précédent
                        </button>
                        <button class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-white bg-blue-500 hover:bg-blue-600">
                            1
                        </button>
                        <button class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                            2
                        </button>
                        <button class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                            3
                        </button>
                        <button class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                            Suivant
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Add Vehicle Modal -->
<div id="addVehicleModal" class="fixed inset-0 z-50 overflow-auto bg-black bg-opacity-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl mx-4"> <!-- Réduit de max-w-4xl à max-w-3xl -->
        <div class="flex justify-between items-center p-4 border-b bg-green-600 rounded-t-lg"> <!-- Ajout de bg-green-600 et p-4 au lieu de p-6 -->
            <!-- <h3 class="text-xl font-semibold text-white">Ajouter un véhicule</h3> Texte en blanc -->
            <a href="create.php" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Ajouter un véhicule
                    </a>
        </div>

        <form action="process/add_vehicle.php" method="POST" class="p-5"> <!-- p-5 au lieu de p-6 -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5"> <!-- gap-5 au lieu de gap-6 -->
                <div>
                    <label for="immatriculation" class="block text-sm font-medium text-gray-700 mb-1">Immatriculation</label>
                    <input type="text" id="immatriculation" name="immatriculation" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500" placeholder="AB-123-CD" required>
                </div>
                <div>
                    <label for="client" class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                    <select id="client" name="client_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500" required>
                        <option value="">Sélectionner un client</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?php echo $client['id']; ?>"><?php echo $client['nom'] . ' ' . $client['prenom']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="marque" class="block text-sm font-medium text-gray-700 mb-1">Marque</label>
                    <select id="marque" name="marque" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500" required>
                        <option value="">Sélectionner une marque</option>
                        <option value="renault">Renault</option>
                        <option value="peugeot">Peugeot</option>
                        <option value="citroen">Citroën</option>
                        <option value="volkswagen">Volkswagen</option>
                        <option value="bmw">BMW</option>
                        <option value="audi">Audi</option>
                        <option value="mercedes">Mercedes</option>
                    </select>
                </div>
                <div>
                    <label for="modele" class="block text-sm font-medium text-gray-700 mb-1">Modèle</label>
                    <input type="text" id="modele" name="modele" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500" placeholder="Clio, 308, etc." required>
                </div>
                <div>
                    <label for="annee" class="block text-sm font-medium text-gray-700 mb-1">Année</label>
                    <input type="number" id="annee" name="annee" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500" placeholder="2023" required>
                </div>
                <div>
                    <label for="kilometrage" class="block text-sm font-medium text-gray-700 mb-1">Kilométrage</label>
                    <input type="number" id="kilometrage" name="kilometrage" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500" placeholder="0" required>
                </div>
                <div>
                    <label for="couleur" class="block text-sm font-medium text-gray-700 mb-1">Couleur</label>
                    <input type="text" id="couleur" name="couleur" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500" placeholder="Noir, Blanc, etc.">
                </div>
                <div>
                    <label for="carburant" class="block text-sm font-medium text-gray-700 mb-1">Type de carburant</label>
                    <select id="carburant" name="carburant" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500">
                        <option value="">Sélectionner un type</option>
                        <option value="essence">Essence</option>
                        <option value="diesel">Diesel</option>
                        <option value="hybride">Hybride</option>
                        <option value="electrique">Électrique</option>
                        <option value="gpl">GPL</option>
                    </select>
                </div>
                <div>
                    <label for="puissance" class="block text-sm font-medium text-gray-700 mb-1">Puissance fiscale (CV)</label>
                    <input type="number" id="puissance" name="puissance" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500" placeholder="5">
                </div>
                <div>
                    <label for="date_achat" class="block text-sm font-medium text-gray-700 mb-1">Date d'achat</label>
                    <input type="date" id="date_achat" name="date_achat" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500">
                </div>
                <div>
                    <label for="date_derniere_revision" class="block text-sm font-medium text-gray-700 mb-1">Date dernière révision</label>
                    <input type="date" id="date_derniere_revision" name="date_derniere_revision" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500">
                </div>
                <div>
                    <label for="date_prochain_ct" class="block text-sm font-medium text-gray-700 mb-1">Date prochain contrôle technique</label>
                    <input type="date" id="date_prochain_ct" name="date_prochain_ct" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500">
                </div>
                <div>
                    <label for="statut" class="block text-sm font-medium text-gray-700 mb-1">Statut</label>
                    <select id="statut" name="statut" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500">
                        <option value="actif">Actif</option>
                        <option value="maintenance">En maintenance</option>
                        <option value="inactif">Inactif</option>
                    </select>
                </div>
            </div>
            
            <div class="mt-5"> <!-- mt-5 au lieu de mt-6 -->
                <label for="notes" class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea id="notes" name="notes" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-green-500 focus:border-green-500" placeholder="Informations complémentaires sur le véhicule..."></textarea>
            </div>

            <div class="mt-5 pt-5 border-t border-gray-200 flex justify-end space-x-3"> <!-- mt-5 et pt-5 au lieu de mt-6 et pt-6 -->
                <button type="button" onclick="closeModal('addVehicleModal')" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Annuler
                </button>
                <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                    Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- View Vehicle Modal -->
<div id="viewVehicleModal" class="fixed inset-0 z-50 overflow-auto bg-black bg-opacity-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl mx-4">
        <div class="flex justify-between items-center p-6 border-b">
            <h3 class="text-xl font-semibold text-gray-900">Détails du véhicule</h3>
            <button onclick="closeModal('viewVehicleModal')" class="text-gray-400 hover:text-gray-500">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <h4 class="text-lg font-semibold text-blue-600 mb-4">Informations générales</h4>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Immatriculation:</span>
                            <span id="view_immatriculation" class="text-sm text-gray-900">AB-123-CD</span>
                        </div>
                                            <div class="flex justify-between">
                        <span class="text-sm font-medium text-gray-500">Marque:</span>
                        <span id="view_marque" class="text-sm text-gray-900 font-medium">
                            <?php
                            // Tableau associatif des marques pour l'affichage formaté
                            $marques = [
                                'abarth' => 'ABARTH',
                                'alfa_romeo' => 'ALFA ROMEO',
                                'audi' => 'AUDI',
                                'bmw' => 'BMW',
                                'byd' => 'BYD',
                                'changan' => 'CHANGAN',
                                'chery' => 'CHERY',
                                'citroen' => 'CITROEN',
                                'cupra' => 'CUPRA',
                                'dacia' => 'DACIA',
                                'dfsk' => 'DFSK',
                                'ds' => 'DS',
                                'fiat' => 'FIAT',
                                'ford' => 'FORD',
                                'geely' => 'GEELY',
                                'gwm' => 'GWM',
                                'honda' => 'HONDA',
                                'hyundai' => 'HYUNDAI',
                                'jaecoo' => 'JAECOO',
                                'jaguar' => 'JAGUAR',
                                'jeep' => 'JEEP',
                                'kia' => 'KIA',
                                'land_rover' => 'LAND ROVER',
                                'lexus' => 'LEXUS',
                                'mahindra' => 'MAHINDRA',
                                'maserati' => 'MASERATI',
                                'mazda' => 'MAZDA',
                                'mercedes' => 'MERCEDES',
                                'mg' => 'MG',
                                'mini' => 'MINI',
                                'mitsubishi' => 'MITSUBISHI',
                                'nissan' => 'NISSAN',
                                'omoda' => 'OMODA',
                                'opel' => 'OPEL',
                                'peugeot' => 'PEUGEOT',
                                'porsche' => 'PORSCHE',
                                'renault' => 'RENAULT',
                                'seat' => 'SEAT',
                                'seres' => 'SERES',
                                'skoda' => 'SKODA',
                                'suzuki' => 'SUZUKI',
                                'toyota' => 'TOYOTA',
                                'volkswagen' => 'VOLKSWAGEN',
                                'volvo' => 'VOLVO',
                                'autre' => 'Autre'
                            ];
                            
                            // Afficher la marque formatée si elle existe dans le tableau, sinon afficher la valeur brute
                            echo isset($marques[$vehicle['marque']]) ? $marques[$vehicle['marque']] : ucfirst($vehicle['marque']);
                            ?>
                        </span>
                    </div>

                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Modèle:</span>
                            <span id="view_modele" class="text-sm text-gray-900">Clio</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Année:</span>
                            <span id="view_annee" class="text-sm text-gray-900">2020</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Couleur:</span>
                            <span id="view_couleur" class="text-sm text-gray-900">Gris</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Type de carburant:</span>
                            <span id="view_carburant" class="text-sm text-gray-900">Essence</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Puissance:</span>
                            <span id="view_puissance" class="text-sm text-gray-900">5 CV</span>
                        </div>
                    </div>

                    <h4 class="text-lg font-semibold text-blue-600 mt-6 mb-4">Propriétaire</h4>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Client:</span>
                            <span id="view_client" class="text-sm text-gray-900">Martin Dupont</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Téléphone:</span>
                            <span id="view_telephone" class="text-sm text-gray-900">06 12 34 56 78</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Email:</span>
                            <span id="view_email" class="text-sm text-gray-900">martin.dupont@email.com</span>
                        </div>
                    </div>
                </div>
                
                <div>
                    <h4 class="text-lg font-semibold text-blue-600 mb-4">Suivi technique</h4>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Kilométrage actuel:</span>
                            <span id="view_kilometrage" class="text-sm text-gray-900">45 000 km</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Date d'achat:</span>
                            <span id="view_date_achat" class="text-sm text-gray-900">15/03/2020</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Dernière révision:</span>
                            <span id="view_date_derniere_revision" class="text-sm text-gray-900">10/09/2022</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Prochain contrôle technique:</span>
                            <span id="view_date_prochain_ct" class="text-sm text-gray-900">15/03/2024</span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Statut:</span>
                            <span id="view_statut" class="text-sm">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                    Actif
                                </span>
                            </span>
                        </div>
                    </div>

                    <h4 class="text-lg font-semibold text-blue-600 mt-6 mb-4">Notes</h4>
                    <div class="bg-gray-50 p-4 rounded-md">
                        <p id="view_notes" class="text-sm text-gray-700">
                            Véhicule en bon état général. Dernière révision complète effectuée à 40 000 km.
                            Pneus avant à remplacer lors de la prochaine révision.
                        </p>
                    </div>
                </div>
            </div>

            <div class="mt-8">
                <h4 class="text-lg font-semibold text-blue-600 mb-4">Historique des interventions</h4>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Description</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Kilométrage</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Montant</th>
                            </tr>
                        </thead>
                        <tbody id="view_interventions" class="bg-white divide-y divide-gray-200">
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">10/09/2022</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">Révision</td>
                                <td class="px-6 py-4 text-sm text-gray-500">Révision complète + changement filtres</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">40 000 km</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">350,00 DH</td>
                            </tr>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">15/03/2022</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">Contrôle technique</td>
                                <td class="px-6 py-4 text-sm text-gray-500">Contrôle technique réglementaire</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">35 500 km</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">80,00 DH</td>
                            </tr>
                            <tr>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">22/11/2021</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">Réparation</td>
                                <td class="px-6 py-4 text-sm text-gray-500">Remplacement batterie</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">32 000 km</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">120,00 DH</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-6 pt-6 border-t border-gray-200 flex justify-end space-x-3">
                <button type="button" onclick="closeModal('viewVehicleModal')" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Fermer
                </button>
                <button type="button" onclick="closeModal('viewVehicleModal'); openModal('editVehicleModal');" class="px-4 py-2 bg-indigo-500 text-white rounded-md hover:bg-indigo-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    Modifier
                </button>
                <button type="button" class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    Ajouter une intervention
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Edit Vehicle Modal -->
<div id="editVehicleModal" class="fixed inset-0 z-50 overflow-auto bg-black bg-opacity-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl mx-4">
        <div class="flex justify-between items-center p-6 border-b">
            <h3 class="text-xl font-semibold text-gray-900">Modifier le véhicule</h3>
            <button onclick="closeModal('editVehicleModal')" class="text-gray-400 hover:text-gray-500">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <form action="process/update_vehicle.php" method="POST" class="p-6">
            <input type="hidden" id="edit_id" name="id" value="1">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="edit_immatriculation" class="block text-sm font-medium text-gray-700 mb-1">Immatriculation</label>
                    <input type="text" id="edit_immatriculation" name="immatriculation" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" value="AB-123-CD" required>
                </div>
                <div>
                    <label for="edit_client" class="block text-sm font-medium text-gray-700 mb-1">Client</label>
                    <select id="edit_client" name="client_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" required>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?php echo $client['id']; ?>"><?php echo $client['nom'] . ' ' . $client['prenom']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
    <label for="edit_marque" class="block text-sm font-medium text-gray-700 mb-1">Marque</label>
    <select id="edit_marque" name="marque" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" required>
        <option value="abarth" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'abarth') ? 'selected' : ''; ?>>ABARTH</option>
        <option value="alfa_romeo" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'alfa_romeo') ? 'selected' : ''; ?>>ALFA ROMEO</option>
        <option value="audi" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'audi') ? 'selected' : ''; ?>>AUDI</option>
        <option value="bmw" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'bmw') ? 'selected' : ''; ?>>BMW</option>
        <option value="byd" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'byd') ? 'selected' : ''; ?>>BYD</option>
        <option value="changan" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'changan') ? 'selected' : ''; ?>>CHANGAN</option>
        <option value="chery" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'chery') ? 'selected' : ''; ?>>CHERY</option>
        <option value="citroen" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'citroen') ? 'selected' : ''; ?>>CITROEN</option>
        <option value="cupra" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'cupra') ? 'selected' : ''; ?>>CUPRA</option>
        <option value="dacia" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'dacia') ? 'selected' : ''; ?>>DACIA</option>
        <option value="dfsk" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'dfsk') ? 'selected' : ''; ?>>DFSK</option>
        <option value="ds" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'ds') ? 'selected' : ''; ?>>DS</option>
        <option value="fiat" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'fiat') ? 'selected' : ''; ?>>FIAT</option>
        <option value="ford" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'ford') ? 'selected' : ''; ?>>FORD</option>
        <option value="geely" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'geely') ? 'selected' : ''; ?>>GEELY</option>
        <option value="gwm" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'gwm') ? 'selected' : ''; ?>>GWM</option>
        <option value="honda" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'honda') ? 'selected' : ''; ?>>HONDA</option>
        <option value="hyundai" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'hyundai') ? 'selected' : ''; ?>>HYUNDAI</option>
        <option value="jaecoo" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'jaecoo') ? 'selected' : ''; ?>>JAECOO</option>
        <option value="jaguar" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'jaguar') ? 'selected' : ''; ?>>JAGUAR</option>
        <option value="jeep" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'jeep') ? 'selected' : ''; ?>>JEEP</option>
        <option value="kia" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'kia') ? 'selected' : ''; ?>>KIA</option>
        <option value="land_rover" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'land_rover') ? 'selected' : ''; ?>>LAND ROVER</option>
        <option value="lexus" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'lexus') ? 'selected' : ''; ?>>LEXUS</option>
        <option value="mahindra" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'mahindra') ? 'selected' : ''; ?>>MAHINDRA</option>
        <option value="maserati" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'maserati') ? 'selected' : ''; ?>>MASERATI</option>
        <option value="mazda" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'mazda') ? 'selected' : ''; ?>>MAZDA</option>
        <option value="mercedes" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'mercedes') ? 'selected' : ''; ?>>MERCEDES</option>
        <option value="mg" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'mg') ? 'selected' : ''; ?>>MG</option>
        <option value="mini" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'mini') ? 'selected' : ''; ?>>MINI</option>
        <option value="mitsubishi" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'mitsubishi') ? 'selected' : ''; ?>>MITSUBISHI</option>
        <option value="nissan" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'nissan') ? 'selected' : ''; ?>>NISSAN</option>
        <option value="omoda" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'omoda') ? 'selected' : ''; ?>>OMODA</option>
        <option value="opel" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'opel') ? 'selected' : ''; ?>>OPEL</option>
        <option value="peugeot" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'peugeot') ? 'selected' : ''; ?>>PEUGEOT</option>
        <option value="porsche" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'porsche') ? 'selected' : ''; ?>>PORSCHE</option>
        <option value="renault" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'renault') ? 'selected' : ''; ?>>RENAULT</option>
        <option value="seat" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'seat') ? 'selected' : ''; ?>>SEAT</option>
        <option value="seres" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'seres') ? 'selected' : ''; ?>>SERES</option>
        <option value="skoda" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'skoda') ? 'selected' : ''; ?>>SKODA</option>
        <option value="suzuki" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'suzuki') ? 'selected' : ''; ?>>SUZUKI</option>
        <option value="toyota" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'toyota') ? 'selected' : ''; ?>>TOYOTA</option>
        <option value="volkswagen" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'volkswagen') ? 'selected' : ''; ?>>VOLKSWAGEN</option>
        <option value="volvo" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'volvo') ? 'selected' : ''; ?>>VOLVO</option>
        <option value="autre" <?php echo (isset($vehicle['marque']) && $vehicle['marque'] == 'autre') ? 'selected' : ''; ?>>Autre</option>
    </select>
</div>

                <div>
                    <label for="edit_modele" class="block text-sm font-medium text-gray-700 mb-1">Modèle</label>
                    <input type="text" id="edit_modele" name="modele" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" value="Clio" required>
                </div>
                <div>
                    <label for="edit_annee" class="block text-sm font-medium text-gray-700 mb-1">Année</label>
                    <input type="number" id="edit_annee" name="annee" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" value="2020" required>
                </div>
                <div>
                    <label for="edit_kilometrage" class="block text-sm font-medium text-gray-700 mb-1">Kilométrage</label>
                    <input type="number" id="edit_kilometrage" name="kilometrage" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" value="45000" required>
                </div>
                <div>
                    
                <div>
                    <label for="edit_couleur" class="block text-sm font-medium text-gray-700 mb-1">Couleur</label>
                    <input type="text" id="edit_couleur" name="couleur" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" value="Gris">
                </div>
                <div>
                    <label for="edit_carburant" class="block text-sm font-medium text-gray">
                <div>
                    <label for="edit_carburant" class="block text-sm font-medium text-gray-700 mb-1">Type de carburant</label>
                    <select id="edit_carburant" name="carburant" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="essence">Essence</option>
                        <option value="diesel">Diesel</option>
                        <option value="hybride">Hybride</option>
                        <option value="electrique">Électrique</option>
                        <option value="gpl">GPL</option>
                    </select>
                </div>
                <div>
                    <label for="edit_puissance" class="block text-sm font-medium text-gray-700 mb-1">Puissance fiscale (CV)</label>
                    <input type="number" id="edit_puissance" name="puissance" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" value="5">
                </div>
                <div>
                    <label for="edit_date_achat" class="block text-sm font-medium text-gray-700 mb-1">Date d'achat</label>
                    <input type="date" id="edit_date_achat" name="date_achat" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" value="2020-03-15">
                </div>
                <div>
                    <label for="edit_date_derniere_revision" class="block text-sm font-medium text-gray-700 mb-1">Date dernière révision</label>
                    <input type="date" id="edit_date_derniere_revision" name="date_derniere_revision" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" value="2022-09-10">
                </div>
                <div>
                    <label for="edit_date_prochain_ct" class="block text-sm font-medium text-gray-700 mb-1">Date prochain contrôle technique</label>
                    <input type="date" id="edit_date_prochain_ct" name="date_prochain_ct" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" value="2024-03-15">
                </div>
                <div>
                    <label for="edit_statut" class="block text-sm font-medium text-gray-700 mb-1">Statut</label>
                    <select id="edit_statut" name="statut" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="actif">Actif</option>
                        <option value="maintenance">En maintenance</option>
                        <option value="inactif">Inactif</option>
                    </select>
                </div>
            </div>
            
            <div class="mt-6">
                <label for="edit_notes" class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
                <textarea id="edit_notes" name="notes" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">Véhicule en bon état général. Dernière révision complète effectuée à 40 000 km. Pneus avant à remplacer lors de la prochaine révision.</textarea>
            </div>

            <div class="mt-6 pt-6 border-t border-gray-200 flex justify-end space-x-3">
                <button type="button" onclick="closeModal('editVehicleModal')" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Annuler
                </button>
                <button type="submit" class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    Enregistrer les modifications
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Vehicle Confirmation Modal -->
<div id="deleteVehicleModal" class="fixed inset-0 z-50 overflow-auto bg-black bg-opacity-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
        <div class="p-6">
            <div class="flex items-center justify-center mb-4">
                <div class="bg-red-100 rounded-full p-3">
                    <svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                    </svg>
                </div>
            </div>
            <h3 class="text-xl font-semibold text-center text-gray-900 mb-4">Confirmer la suppression</h3>
            <p class="text-gray-700 text-center mb-6">Êtes-vous sûr de vouloir supprimer ce véhicule ? Cette action est irréversible.</p>
            <div class="flex justify-center space-x-4">
                <button type="button" onclick="closeModal('deleteVehicleModal')" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Annuler
                </button>
                <form id="deleteVehicleForm" action="process/delete_vehicle.php" method="POST">
                    <input type="hidden" id="delete_id" name="id" value="">
                    <button type="submit" class="px-4 py-2 bg-red-500 text-white rounded-md hover:bg-red-600 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                        Supprimer
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    // Function to open modal
    function openModal(modalId) {
        document.getElementById(modalId).classList.remove('hidden');
    }

    // Function to close modal
    function closeModal(modalId) {
        document.getElementById(modalId).classList.add('hidden');
    }

    // Function to view vehicle details
    function viewVehicle(id) {
    // Faire une requête pour récupérer les détails du véhicule depuis la base de données
    fetch(`recuperation_vehi.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            // Remplir les champs du modal avec les données récupérées
            document.getElementById('view_immatriculation').textContent = data.immatriculation;
            document.getElementById('view_marque').textContent = data.marque;
            document.getElementById('view_modele').textContent = data.modele;
            document.getElementById('view_annee').textContent = data.annee;
            document.getElementById('view_couleur').textContent = data.couleur;
            document.getElementById('view_carburant').textContent = data.carburant;
            document.getElementById('view_puissance').textContent = data.puissance;
            document.getElementById('view_client').textContent = data.client_nom; // Exemple : client_nom ou autre
            document.getElementById('view_telephone').textContent = data.client_telephone; // Exemple : client_telephone
            document.getElementById('view_email').textContent = data.client_email; // Exemple : client_email
            document.getElementById('view_kilometrage').textContent = data.kilometrage;
            document.getElementById('view_date_achat').textContent = data.date_achat;
            document.getElementById('view_date_derniere_revision').textContent = data.date_derniere_revision;
            document.getElementById('view_date_prochain_ct').textContent = data.date_prochain_ct;
            document.getElementById('view_statut').textContent = data.statut;

            // Afficher les notes
            document.getElementById('view_notes').textContent = data.notes;

            // Récupérer et afficher les interventions (historique)
          /*   const interventionsTable = document.getElementById('view_interventions');
            interventionsTable.innerHTML = ''; // Vider la table existante

            // Ajouter les lignes des interventions
            data.interventions.forEach(intervention => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td class="px-6 py-4 text-sm text-gray-500">${intervention.date}</td>
                    <td class="px-6 py-4 text-sm text-gray-500">${intervention.type}</td>
                    <td class="px-6 py-4 text-sm text-gray-500">${intervention.description}</td>
                    <td class="px-6 py-4 text-sm text-gray-500">${intervention.kilometrage}</td>
                    <td class="px-6 py-4 text-sm text-gray-500">${intervention.montant}</td>
                `;
                interventionsTable.appendChild(row);
            });
 */
            // Afficher le modal
            openModal('viewVehicleModal');
        })
        .catch(error => console.error('Erreur lors de la récupération des données du véhicule:', error));
}

    // Function to delete vehicle
    function deleteVehicle(id) {
        document.getElementById('delete_id').value = id;
        openModal('deleteVehicleModal');
    }
   
    


    // Close modals when clicking outside
    window.onclick = function(event) {
        const modals = document.querySelectorAll('.fixed.inset-0');
        modals.forEach(modal => {
            if (event.target === modal) {
                modal.classList.add('hidden');
            }
        });
    }
</script>

<?php include '../includes/footer.php'; ?>
