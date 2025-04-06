<?php
// Démarrer la session
session_start();

// Chemin racine de l'application
$root_path = dirname(__DIR__);

// Inclure les fichiers de configuration et de fonctions
$required_files = [
    '/config/database.php',
    '/includes/functions.php'
];

foreach ($required_files as $file) {
    $file_path = $root_path . $file;
    if (file_exists($file_path)) {
        require_once $file_path;
    }
}

// Connexion à la base de données
$database = new Database();
$conn = $database->getConnection();

// Vérifier si l'utilisateur est connecté, sinon rediriger vers la page de connexion
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Récupérer les informations de l'utilisateur connecté
$currentUser = [];
if (isset($_SESSION['user_id'])) {
    $user_id = $_SESSION['user_id'];
    try {
        $stmt = $conn->prepare("SELECT nom, prenom, role FROM users WHERE id = ?");
        $stmt->bindParam(1, $user_id, PDO::PARAM_INT);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            $currentUser = [
                'name' => $user['prenom'] . ' ' . $user['nom'],
                'role' => $user['role']
            ];
        }
    } catch (PDOException $e) {
        // Gérer l'erreur silencieusement et logger si nécessaire
        error_log("Erreur lors de la récupération des informations utilisateur: " . $e->getMessage());
    }
}

// Vérifier si un ID client est fourni
if (!isset($_GET['id']) || empty($_GET['id']) || !is_numeric($_GET['id'])) {
    // Rediriger vers la liste des clients si aucun ID valide n'est fourni
    header('Location: index.php');
    exit;
}

$client_id = intval($_GET['id']);

// Récupérer les informations du client depuis la base de données
try {
    $stmt = $conn->prepare("SELECT c.*, tc.type as type_client FROM clients c 
                            LEFT JOIN type_client tc ON c.type_client_id = tc.id 
                            WHERE c.id = ?");
    $stmt->bindParam(1, $client_id, PDO::PARAM_INT);
    $stmt->execute();
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        // Rediriger vers la liste des clients si le client n'existe pas
        header('Location: index.php');
        exit;
    }
} catch (PDOException $e) {
    // Gérer l'erreur et rediriger
    error_log("Erreur lors de la récupération du client: " . $e->getMessage());
    header('Location: index.php?error=db');
    exit;
}

// Ajouter la date de création formatée pour l'affichage
$client['date_creation_formatted'] = date('d/m/Y', strtotime($client['date_creation']));

// Préparer le nom d'affichage du client selon son type
if ($client['type_client_id'] == 2) {
    // Pour une société, utiliser la raison sociale comme nom principal
    $client['display_name'] = htmlspecialchars($client['raison_sociale']);
    // Mais garder aussi le nom du contact si disponible
    $client['contact_name'] = !empty($client['prenom']) || !empty($client['nom']) ? 
        htmlspecialchars(trim($client['prenom'] . ' ' . $client['nom'])) : '';
} else {
    // Pour un particulier, utiliser prénom + nom
    $client['display_name'] = htmlspecialchars(trim($client['prenom'] . ' ' . $client['nom']));
}

// Récupérer les véhicules du client
try {
    $stmt = $conn->prepare("SELECT * FROM vehicules WHERE client_id = ? ORDER BY date_creation DESC");
    $stmt->bindParam(1, $client_id, PDO::PARAM_INT);
    $stmt->execute();
    $vehicules = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $vehicules = [];
    error_log("Erreur lors de la récupération des véhicules: " . $e->getMessage());
}

// Récupérer les interventions du client à travers ses véhicules
try {
    $stmt = $conn->prepare("SELECT i.*, v.marque, v.modele, v.immatriculation 
                            FROM interventions i 
                            JOIN vehicules v ON i.vehicule_id = v.id 
                            WHERE v.client_id = ? 
                            ORDER BY i.date_creation DESC");
    $stmt->bindParam(1, $client_id, PDO::PARAM_INT);
    $stmt->execute();
    $interventions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($interventions as &$row) {
        // Formater le véhicule pour l'affichage
        $row['vehicule'] = htmlspecialchars($row['marque'] . ' ' . $row['modele'] . ' (' . $row['immatriculation'] . ')');
    }
} catch (PDOException $e) {
    $interventions = [];
    error_log("Erreur lors de la récupération des interventions: " . $e->getMessage());
}

// Récupérer les factures liées aux interventions du client
$factures = [];
$total_factures = 0;

if (!empty($interventions)) {
    $intervention_ids = array_column($interventions, 'id');

    if (!empty($intervention_ids)) {
        try {
            $placeholders = implode(',', array_fill(0, count($intervention_ids), '?'));
            $sql = "SELECT * FROM factures WHERE intervention_id IN ($placeholders) ORDER BY date_creation DESC";
            $stmt = $conn->prepare($sql);
            
            foreach ($intervention_ids as $key => $id) {
                $stmt->bindValue($key + 1, $id, PDO::PARAM_INT);
            }

            $stmt->execute();
            $factures = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($factures as $facture) {
                $total_factures += $facture['montant_ttc'];
            }
        } catch (PDOException $e) {
            error_log("Erreur lors de la récupération des factures: " . $e->getMessage());
        }
    }
}

// Déterminer la date de la dernière visite (dernière intervention)
$derniere_visite = !empty($interventions) ? $interventions[0]['date_creation'] : $client['date_creation'];

// Calculer le nombre de véhicules
$nb_vehicules = count($vehicules);

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
                <h1 class="text-2xl font-semibold text-gray-800">Détails du client</h1>
                
            </div>
        </div>

        <!-- Client Details Content -->
        <div class="container mx-auto px-6 py-8">
            <!-- Breadcrumb -->
            <nav class="mb-6" aria-label="Breadcrumb">
                <ol class="flex text-sm text-gray-600">
                    <li>
                        <a href="<?php echo $root_path; ?>/dashboard.php" class="hover:text-indigo-600">Tableau de bord</a>
                    </li>
                    <li class="mx-2">/</li>
                    <li>
                        <a href="index.php" class="hover:text-indigo-600">Clients</a>
                    </li>
                    <li class="mx-2">/</li>
                    <li class="text-gray-800 font-medium">Détails</li>
                </ol>
            </nav>
            
            <!-- Client Header -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden mb-6">
                <div class="p-6">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                        <div class="flex items-center mb-4 md:mb-0">
                            <div class="h-16 w-16 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 text-xl font-semibold mr-4">
                                <?php 
                                // Initiales basées sur le nom d'affichage
                                if ($client['type_client_id'] == 2) {
                                    // Pour une société, utiliser les initiales de la raison sociale
                                    $words = explode(' ', $client['raison_sociale']);
                                    $initials = '';
                                    foreach ($words as $word) {
                                        if (!empty($word)) {
                                            $initials .= substr($word, 0, 1);
                                            if (strlen($initials) >= 2) break;
                                        }
                                    }
                                    // Si on n'a pas assez d'initiales, compléter
                                    if (strlen($initials) < 2 && !empty($client['raison_sociale'])) {
                                        $initials = substr($client['raison_sociale'], 0, 2);
                                    }
                                    echo htmlspecialchars(strtoupper($initials));
                                } else {
                                    // Pour un particulier, utiliser l'initiale du prénom et du nom
                                    $prenom_initial = !empty($client['prenom']) ? substr($client['prenom'], 0, 1) : '';
                                    $nom_initial = !empty($client['nom']) ? substr($client['nom'], 0, 1) : '';
                                    echo htmlspecialchars(strtoupper($prenom_initial . $nom_initial));
                                }
                                ?>
                            </div>
                            <div>
                                <h2 class="text-2xl font-semibold text-gray-800">
                                    <?php echo $client['display_name']; ?>
                                </h2>
                                <?php if ($client['type_client_id'] == 2 && !empty($client['contact_name'])): ?>
                                <p class="text-gray-600">Contact: <?php echo $client['contact_name']; ?></p>
                                <?php endif; ?>
                                <p class="text-gray-600">Client depuis le <?php echo date('d/m/Y', strtotime($client['date_creation'])); ?></p>
                                <div class="flex items-center mt-1">
                                    <span class="px-2 py-1 text-xs rounded-full <?php echo strtotime($derniere_visite) > strtotime('-3 months') ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'; ?> mr-2">
                                        <?php echo strtotime($derniere_visite) > strtotime('-3 months') ? 'Client actif' : 'Inactif depuis ' . date('d/m/Y', strtotime($derniere_visite)); ?>
                                    </span>
                                    <span class="px-2 py-1 text-xs rounded-full bg-blue-100 text-blue-800">
                                        <?php echo $nb_vehicules; ?> véhicule<?php echo $nb_vehicules > 1 ? 's' : ''; ?>
                                    </span>
                                    <?php if ($client['type_client_id'] == 2): ?>
                                    <span class="px-2 py-1 text-xs rounded-full bg-purple-100 text-purple-800 ml-2">
                                        Société
                                    </span>
                                    <?php else: ?>
                                    <span class="px-2 py-1 text-xs rounded-full bg-gray-100 text-gray-800 ml-2">
                                        Particulier
                                    </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="flex space-x-2">
                            <a href="edit.php?id=<?php echo $client['id']; ?>" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition flex items-center">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                </svg>
                                Modifier
                            </a>
                            <button onclick="window.print()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition flex items-center">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                                </svg>
                                Imprimer
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Client Info and Tabs -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <!-- Left Column - Client Info -->
                <div class="md:col-span-1">
                    <div class="bg-white rounded-lg shadow-md overflow-hidden">
                        <div class="p-6">
                            <h3 class="text-lg font-semibold text-gray-800 mb-4">Informations de contact</h3>
                            
                            <div class="space-y-4">
                                <?php if ($client['type_client_id'] == 2): ?>
                                <div class="flex items-start">
                                    <svg class="w-5 h-5 text-gray-500 mt-0.5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                    </svg>
                                    <div>
                                        <p class="text-sm text-gray-500">Raison sociale</p>
                                        <p class="text-gray-800"><?php echo htmlspecialchars($client['raison_sociale']); ?></p>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="flex items-start">
                                    <svg class="w-5 h-5 text-gray-500 mt-0.5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                                    </svg>
                                    <div>
                                        <p class="text-sm text-gray-500">Nom complet</p>
                                        <p class="text-gray-800"><?php echo htmlspecialchars($client['prenom'] . ' ' . $client['nom']); ?></p>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <div class="flex items-start">
                                    <svg class="w-5 h-5 text-gray-500 mt-0.5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                    </svg>
                                    <div>
                                        <p class="text-sm text-gray-500">Email</p>
                                        <p class="text-gray-800"><?php echo !empty($client['email']) ? htmlspecialchars($client['email']) : 'Non renseigné'; ?></p>
                                    </div>
                                </div>
                                
                                <div class="flex items-start">
                                    <svg class="w-5 h-5 text-gray-500 mt-0.5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
                                    </svg>
                                    <div>
                                        <p class="text-sm text-gray-500">Téléphone</p>
                                        <p class="text-gray-800"><?php echo !empty($client['telephone']) ? htmlspecialchars($client['telephone']) : 'Non renseigné'; ?></p>
                                    </div>
                                </div>
                                
                                <div class="flex items-start">
                                    <svg class="w-5 h-5 text-gray-500 mt-0.5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                    </svg>
                                    <div>
                                        <p class="text-sm text-gray-500">Adresse</p>
                                        <?php if (!empty($client['adresse'])): ?>
                                            <p class="text-gray-800"><?php echo htmlspecialchars($client['adresse']); ?></p>
                                            <?php if (!empty($client['code_postal']) || !empty($client['ville'])): ?>
                                                <p class="text-gray-800"><?php echo htmlspecialchars(trim($client['code_postal'] . ' ' . $client['ville'])); ?></p>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <p class="text-gray-800">Non renseignée</p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <?php if ($client['type_client_id'] == 2): ?>
                                <div class="flex items-start">
                                    <svg class="w-5 h-5 text-gray-500 mt-0.5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                    </svg>
                                    <div>
                                        <p class="text-sm text-gray-500">Registre RCC</p>
                                        <p class="text-gray-800"><?php echo !empty($client['registre_rcc']) ? htmlspecialchars($client['registre_rcc']) : 'Non renseigné'; ?></p>
                                    </div>
                                </div>
                                <div class="flex items-start">
                                <svg class="w-5 h-5 text-gray-500 mt-0.5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6l4 2M12 22a10 10 0 110-20 10 10 0 010 20z"></path>
                                </svg>

                                    <div>
                                        <p class="text-sm text-gray-500">Délai de paiement</p>
                                        <p class="text-gray-800"><?php echo isset($client['delai_paiement']) && $client['delai_paiement'] > 0 ? htmlspecialchars($client['delai_paiement'] . ' jours') : 'Paiement immédiat'; ?></p>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($client['notes'])): ?>
                                <div class="flex items-start pt-2 border-t border-gray-100">
                                    <svg class="w-5 h-5 text-gray-500 mt-0.5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                    </svg>
                                    <div>
                                        <p class="text-sm text-gray-500">Notes</p>
                                        <p class="text-gray-800"><?php echo htmlspecialchars($client['notes']); ?></p>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="mt-6 pt-6 border-t border-gray-200">
                                <h3 class="text-lg font-semibold text-gray-800 mb-4">Statistiques</h3>
                                
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="bg-gray-50 p-3 rounded-lg">
                                        <div class="text-xs text-gray-500">Véhicules</div>
                                        <div class="text-xl font-semibold text-indigo-600"><?php echo $nb_vehicules; ?></div>
                                    </div>
                                    <div class="bg-gray-50 p-3 rounded-lg">
                                        <div class="text-xs text-gray-500">Interventions</div>
                                        <div class="text-xl font-semibold text-indigo-600"><?php echo count($interventions); ?></div>
                                    </div>
                                    <div class="bg-gray-50 p-3 rounded-lg">
                                        <div class="text-xs text-gray-500">Factures</div>
                                        <div class="text-xl font-semibold text-indigo-600"><?php echo count($factures); ?></div>
                                    </div>
                                    <div class="bg-gray-50 p-3 rounded-lg">
                                        <div class="text-xs text-gray-500">Total facturé</div>
                                        <div class="text-xl font-semibold text-indigo-600"><?php echo number_format($total_factures, 2, ',', ' '); ?> DH</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Right Column - Tabs for Vehicles, Interventions, Invoices -->
                <div class="md:col-span-2">
                    <div class="bg-white rounded-lg shadow-md overflow-hidden">
                        <!-- Tabs Header -->
                        <div class="border-b border-gray-200">
                            <nav class="flex -mb-px">
                                <button id="tab-vehicles" class="tab-button active text-indigo-600 border-indigo-500 py-4 px-6 font-medium border-b-2">
                                    Véhicules (<?php echo count($vehicules); ?>)
                                </button>
                                <button id="tab-interventions" class="tab-button text-gray-500 hover:text-gray-700 py-4 px-6 font-medium border-b-2 border-transparent hover:border-gray-300">
                                    Interventions (<?php echo count($interventions); ?>)
                                </button>
                                <button id="tab-invoices" class="tab-button text-gray-500 hover:text-gray-700 py-4 px-6 font-medium border-b-2 border-transparent hover:border-gray-300">
                                    Factures (<?php echo count($factures); ?>)
                                </button>
                            </nav>
                        </div>
                        
                        <!-- Tab Content -->
                        <div class="p-6">
                            <!-- Vehicles Tab -->
                            <div id="content-vehicles" class="tab-content block">
                                <div class="flex justify-between items-center mb-4">
                                    <h3 class="text-lg font-semibold text-gray-800">Liste des véhicules</h3>
                                    <a href="<?php echo $root_path; ?>/vehicles/create.php?client_id=<?php echo $client_id; ?>" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition flex items-center">
                                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                        </svg>
                                        Ajouter un véhicule
                                    </a>
                                </div>
                                
                                <?php if (empty($vehicules)): ?>
                                    <div class="bg-gray-50 rounded-lg p-4 text-center">
                                        <p class="text-gray-600">Aucun véhicule enregistré pour ce client.</p>
                                        <p class="mt-2">
                                            <a href="<?php echo $root_path; ?>/vehicles/create.php?client_id=<?php echo $client_id; ?>" class="text-indigo-600 hover:text-indigo-800">
                                                Ajouter un premier véhicule
                                            </a>
                                        </p>
                                    </div>
                                <?php else: ?>
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200">
                                            <thead class="bg-gray-50">
                                                <tr>
                                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                        Véhicule
                                                    </th>
                                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                        Immatriculation
                                                    </th>
                                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                        Année / Kilométrage
                                                    </th>
                                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                        Dernière révision
                                                    </th>
                                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                        Actions
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody class="bg-white divide-y divide-gray-200">
                                                <?php foreach ($vehicules as $vehicle): ?>
                                                    <tr class="hover:bg-gray-50">
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <div class="text-sm font-medium text-gray-900">
                                                                <?php echo htmlspecialchars(ucfirst($vehicle['marque']) . ' ' . $vehicle['modele']); ?>
                                                            </div>
                                                            <?php if (!empty($vehicle['carburant'])): ?>
                                                            <div class="text-xs text-gray-500">
                                                                <?php echo htmlspecialchars(ucfirst($vehicle['carburant'])); ?>
                                                            </div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <div class="text-sm text-gray-900">
                                                                <?php echo htmlspecialchars($vehicle['immatriculation']); ?>
                                                            </div>
                                                            <div class="text-xs text-gray-500">
                                                                <?php echo $vehicle['statut'] === 'actif' ? 
                                                                    '<span class="text-green-600">Actif</span>' : 
                                                                    '<span class="text-red-600">Inactif</span>'; ?>
                                                            </div>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <div class="text-sm text-gray-900">
                                                                <?php echo !empty($vehicle['annee']) ? $vehicle['annee'] : 'N/A'; ?>
                                                            </div>
                                                            <div class="text-sm text-gray-500">
                                                                <?php echo !empty($vehicle['kilometrage']) ? 
                                                                    number_format($vehicle['kilometrage'], 0, ',', ' ') . ' km' : 
                                                                    'N/A'; ?>
                                                            </div>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <div class="text-sm text-gray-900">
                                                                <?php 
                                                                if (!empty($vehicle['date_derniere_revision']) && $vehicle['date_derniere_revision'] != '0000-00-00') {
                                                                    echo date('d/m/Y', strtotime($vehicle['date_derniere_revision']));
                                                                    
                                                                    // Vérifier si la dernière révision date de plus de 1 an
                                                                    $last_revision = new DateTime($vehicle['date_derniere_revision']);
                                                                    $now = new DateTime();
                                                                    $interval = $now->diff($last_revision);
                                                                    
                                                                    if ($interval->y >= 1) {
                                                                        echo '<span class="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                                                                                À réviser
                                                                              </span>';
                                                                    }
                                                                } else {
                                                                    echo '<span class="text-gray-500">Non définie</span>';
                                                                }
                                                                ?>
                                                            </div>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                            <div class="flex justify-end space-x-2">
                                                                <a href="<?php echo $root_path; ?>/vehicles/view.php?id=<?php echo $vehicle['id']; ?>" class="text-indigo-600 hover:text-indigo-900" title="Voir">
                                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                                    </svg>
                                                                </a>
                                                                <a href="<?php echo $root_path; ?>/vehicles/edit.php?id=<?php echo $vehicle['id']; ?>" class="text-blue-600 hover:text-blue-900" title="Modifier">
                                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                                    </svg>
                                                                </a>
                                                                <a href="<?php echo $root_path; ?>/interventions/create.php?vehicle_id=<?php echo $vehicle['id']; ?>" class="text-green-600 hover:text-green-900" title="Ajouter intervention">
                                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                                                                    </svg>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Interventions Tab -->
                            <div id="content-interventions" class="tab-content hidden">
                                <div class="flex justify-between items-center mb-4">
                                    <h3 class="text-lg font-semibold text-gray-800">Historique des interventions</h3>
                                    <?php if (!empty($vehicules)): ?>
                                    <div class="relative inline-block text-left" x-data="{ open: false }">
                                        <button @click="open = !open" type="button" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition flex items-center">
                                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                            </svg>
                                            Nouvelle intervention
                                        </button>
                                        <div x-show="open" @click.away="open = false" class="origin-top-right absolute right-0 mt-2 w-56 rounded-md shadow-lg bg-white ring-1 ring-black ring-opacity-5 focus:outline-none z-10">
                                            <div class="py-1" role="menu" aria-orientation="vertical">
                                                <?php foreach ($vehicules as $vehicle): ?>
                                                <a href="<?php echo $root_path; ?>/interventions/create.php?vehicle_id=<?php echo $vehicle['id']; ?>" class="block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100" role="menuitem">
                                                    <?php echo htmlspecialchars(ucfirst($vehicle['marque']) . ' ' . $vehicle['modele'] . ' (' . $vehicle['immatriculation'] . ')'); ?>
                                                </a>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if (empty($interventions)): ?>
                                    <div class="bg-gray-50 rounded-lg p-4 text-center">
                                        <p class="text-gray-600">Aucune intervention enregistrée pour ce client.</p>
                                        <?php if (!empty($vehicules)): ?>
                                        <p class="mt-2">
                                            <a href="<?php echo $root_path; ?>/interventions/create.php?vehicle_id=<?php echo $vehicules[0]['id']; ?>" class="text-indigo-600 hover:text-indigo-800">
                                                Ajouter une première intervention
                                            </a>
                                        </p>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200">
                                            <thead class="bg-gray-50">
                                                <tr>
                                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                        Date
                                                    </th>
                                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                        Véhicule
                                                    </th>
                                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                        Description
                                                    </th>
                                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                        Kilométrage
                                                    </th>
                                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                        Statut
                                                    </th>
                                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                        Actions
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody class="bg-white divide-y divide-gray-200">
                                                <?php foreach ($interventions as $intervention): ?>
                                                    <tr class="hover:bg-gray-50">
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <div class="text-sm text-gray-900">
                                                                <?php echo date('d/m/Y', strtotime($intervention['date_creation'])); ?>
                                                            </div>
                                                            <?php if (!empty($intervention['date_debut']) && $intervention['date_debut'] != '0000-00-00 00:00:00'): ?>
                                                            <div class="text-xs text-gray-500">
                                                                Début: <?php echo date('d/m/Y', strtotime($intervention['date_debut'])); ?>
                                                            </div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <div class="text-sm text-gray-900"><?php echo $intervention['vehicule']; ?></div>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <div class="text-sm text-gray-900">
                                                                <?php 
                                                                $description = $intervention['description'];
                                                                echo htmlspecialchars(strlen($description) > 30 ? 
                                                                    substr($description, 0, 30) . '...' : 
                                                                    $description); 
                                                                ?>
                                                            </div>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <div class="text-sm text-gray-900">
                                                                <?php echo !empty($intervention['kilometrage']) ? 
                                                                    number_format($intervention['kilometrage'], 0, ',', ' ') . ' km' : 
                                                                    'N/A'; ?>
                                                            </div>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                                <?php 
                                                                switch ($intervention['statut']) {
                                                                    case 'Terminée':
                                                                        echo 'bg-green-100 text-green-800';
                                                                        break;
                                                                    case 'En cours':
                                                                        echo 'bg-yellow-100 text-yellow-800';
                                                                        break;
                                                                    case 'En attente':
                                                                        echo 'bg-blue-100 text-blue-800';
                                                                        break;
                                                                    case 'Facturée':
                                                                        echo 'bg-purple-100 text-purple-800';
                                                                        break;
                                                                    case 'Annulée':
                                                                        echo 'bg-red-100 text-red-800';
                                                                        break;
                                                                    default:
                                                                        echo 'bg-gray-100 text-gray-800';
                                                                }
                                                                ?>">
                                                                <?php echo htmlspecialchars($intervention['statut']); ?>
                                                            </span>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                            <div class="flex justify-end space-x-2">
                                                                <a href="<?php echo $root_path; ?>/interventions/view.php?id=<?php echo $intervention['id']; ?>" class="text-indigo-600 hover:text-indigo-900" title="Voir">
                                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                                    </svg>
                                                                </a>
                                                                <a href="<?php echo $root_path; ?>/interventions/edit.php?id=<?php echo $intervention['id']; ?>" class="text-blue-600 hover:text-blue-900" title="Modifier">
                                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                                    </svg>
                                                                </a>
                                                                <?php if ($intervention['statut'] == 'Terminée'): ?>
                                                                <a href="<?php echo $root_path; ?>/invoices/create.php?intervention_id=<?php echo $intervention['id']; ?>" class="text-green-600 hover:text-green-900" title="Facturer">
                                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                                                                    </svg>
                                                                </a>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Invoices Tab -->
                            <div id="content-invoices" class="tab-content hidden">
                                <div class="flex justify-between items-center mb-4">
                                    <h3 class="text-lg font-semibold text-gray-800">Factures</h3>
                                </div>
                                
                                <?php if (empty($factures)): ?>
                                    <div class="bg-gray-50 rounded-lg p-4 text-center">
                                        <p class="text-gray-600">Aucune facture enregistrée pour ce client.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200">
                                            <thead class="bg-gray-50">
                                                <tr>
                                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                        Numéro
                                                    </th>
                                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                        Date
                                                    </th>
                                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                        Montant
                                                    </th>
                                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                        Statut
                                                    </th>
                                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                                        Actions
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody class="bg-white divide-y divide-gray-200">
                                                <?php foreach ($factures as $facture): ?>
                                                    <tr class="hover:bg-gray-50">
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <div class="text-sm font-medium text-gray-900">
                                                                <?php echo htmlspecialchars($facture['numero']); ?>
                                                            </div>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <div class="text-sm text-gray-900">
                                                                <?php echo date('d/m/Y', strtotime($facture['date_creation'])); ?>
                                                            </div>
                                                            <?php if (!empty($facture['date_paiement'])): ?>
                                                            <div class="text-xs text-gray-500">
                                                                Payée le: <?php echo date('d/m/Y', strtotime($facture['date_paiement'])); ?>
                                                            </div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <div class="text-sm text-gray-900">
                                                                <?php echo number_format($facture['montant_ttc'], 2, ',', ' '); ?> DH
                                                            </div>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap">
                                                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full 
                                                                <?php 
                                                                switch ($facture['statut']) {
                                                                    case 'Payée':
                                                                        echo 'bg-green-100 text-green-800';
                                                                        break;
                                                                    case 'En attente':
                                                                    case 'Émise':
                                                                        echo 'bg-yellow-100 text-yellow-800';
                                                                        break;
                                                                    case 'Annulée':
                                                                        echo 'bg-red-100 text-red-800';
                                                                        break;
                                                                    default:
                                                                        echo 'bg-gray-100 text-gray-800';
                                                                }
                                                                ?>">
                                                                <?php echo htmlspecialchars($facture['statut']); ?>
                                                            </span>
                                                        </td>
                                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                            <div class="flex justify-end space-x-2">
                                                                <a href="<?php echo $root_path; ?>/invoices/view.php?id=<?php echo $facture['id']; ?>" class="text-indigo-600 hover:text-indigo-900" title="Voir">
                                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                                    </svg>
                                                                </a>
                                                                <a href="<?php echo $root_path; ?>/invoices/print.php?id=<?php echo $facture['id']; ?>" class="text-green-600 hover:text-green-900" title="Imprimer">
                                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                                                                    </svg>
                                                                </a>
                                                                <a href="#" onclick="sendInvoiceEmail(<?php echo $facture['id']; ?>)" class="text-blue-600 hover:text-blue-900" title="Envoyer par email">
                                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
                                                                    </svg>
                                                                </a>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                
                    </div>
            </div>
        </div>
    </div>
</div>

<!-- JavaScript pour tabs functionality et Alpine.js pour les dropdowns -->
<script src="https://cdn.jsdelivr.net/npm/alpinejs@2.8.2/dist/alpine.min.js" defer></script>
<script>
    // Tab switching functionality
    document.addEventListener('DOMContentLoaded', function() {
        const tabButtons = document.querySelectorAll('.tab-button');
        const tabContents = document.querySelectorAll('.tab-content');
        
        tabButtons.forEach(button => {
            button.addEventListener('click', () => {
                // Get the target content id
                const target = button.id.replace('tab-', 'content-');
                
                // Remove active class from all buttons and add to clicked button
                tabButtons.forEach(btn => {
                    btn.classList.remove('active', 'text-indigo-600', 'border-indigo-500');
                    btn.classList.add('text-gray-500', 'border-transparent');
                });
                button.classList.remove('text-gray-500', 'border-transparent');
                button.classList.add('active', 'text-indigo-600', 'border-indigo-500');
                
                // Hide all tab contents and show the target content
                tabContents.forEach(content => content.classList.add('hidden'));
                document.getElementById(target).classList.remove('hidden');
            });
        });
        
        // Check if there's a hash in the URL to open a specific tab
        const hash = window.location.hash;
        if (hash) {
            const tabId = hash.replace('#', 'tab-');
            const tabButton = document.getElementById(tabId);
            if (tabButton) {
                tabButton.click();
            }
        }
    });

    // Email sending functionality with feedback
    function sendEmail() {
        // Afficher un indicateur de chargement
        const emailButton = event.currentTarget;
        const originalContent = emailButton.innerHTML;
        emailButton.innerHTML = '<svg class="animate-spin h-5 w-5 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Envoi...';
        emailButton.disabled = true;
        
        // Simuler un envoi d'email (à remplacer par un appel AJAX réel)
        setTimeout(() => {
            emailButton.innerHTML = originalContent;
            emailButton.disabled = false;
            
            // Afficher une notification de succès
            const notification = document.createElement('div');
            notification.className = 'fixed bottom-4 right-4 bg-green-500 text-white px-4 py-2 rounded shadow-lg transition-opacity duration-500';
            notification.textContent = 'Un email a été envoyé à <?php echo htmlspecialchars($client['email']); ?>';
            document.body.appendChild(notification);
            
            // Faire disparaître la notification après 3 secondes
            setTimeout(() => {
                notification.style.opacity = '0';
                setTimeout(() => notification.remove(), 500);
            }, 3000);
        }, 1500);
    }
    
    // Invoice email sending functionality with feedback
    function sendInvoiceEmail(invoiceId) {
        // Afficher un indicateur de chargement
        const emailButton = event.currentTarget;
        const originalContent = emailButton.innerHTML;
        emailButton.innerHTML = '<svg class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';
        emailButton.disabled = true;
        
        // Simuler un envoi d'email (à remplacer par un appel AJAX réel)
        setTimeout(() => {
            // Effectuer une requête AJAX pour envoyer la facture par email
            fetch(`<?php echo $root_path; ?>/api/send_invoice.php?id=${invoiceId}`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(response => response.json())
            .then(data => {
                emailButton.innerHTML = originalContent;
                emailButton.disabled = false;
                
                // Afficher une notification
                const notification = document.createElement('div');
                notification.className = `fixed bottom-4 right-4 ${data.success ? 'bg-green-500' : 'bg-red-500'} text-white px-4 py-2 rounded shadow-lg transition-opacity duration-500`;
                notification.textContent = data.success ? 
                    'La facture a été envoyée par email à <?php echo htmlspecialchars($client['email']); ?>' : 
                    `Erreur lors de l'envoi: ${data.message}`;
                document.body.appendChild(notification);
                
                // Faire disparaître la notification après 3 secondes
                setTimeout(() => {
                    notification.style.opacity = '0';
                    setTimeout(() => notification.remove(), 500);
                }, 3000);
            })
            .catch(error => {
                console.error('Erreur:', error);
                emailButton.innerHTML = originalContent;
                emailButton.disabled = false;
                
                // Notification d'erreur
                const notification = document.createElement('div');
                notification.className = 'fixed bottom-4 right-4 bg-red-500 text-white px-4 py-2 rounded shadow-lg transition-opacity duration-500';
                notification.textContent = 'Erreur lors de l\'envoi de l\'email';
                document.body.appendChild(notification);
                
                setTimeout(() => {
                    notification.style.opacity = '0';
                    setTimeout(() => notification.remove(), 500);
                }, 3000);
            });
        }, 800);
    }
</script>

<?php include $root_path . '/includes/footer.php'; ?>
