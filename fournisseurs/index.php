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

// Vérifier si l'utilisateur est connecté, sinon rediriger vers la page de connexion
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}
$database = new Database();
$db = $database->getConnection();
// Récupérer les informations de l'utilisateur actuel
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


try {
    $query = "SELECT id, username, nom, prenom, role FROM users WHERE id = :user_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $currentUser = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // Si l'utilisateur n'existe pas, déconnecter
        session_destroy();
        header('Location: ../auth/login.php');
        exit;
    }
} catch (PDOException $e) {
    // Log l'erreur et rediriger vers une page d'erreur
    error_log('Erreur de récupération des données utilisateur: ' . $e->getMessage());
    header('Location: ../error.php');
    exit;
}

// Assurer que $currentUser['name'] est défini pour éviter les erreurs
if (!isset($currentUser['name'])) {
    $currentUser['name'] = 'Utilisateur Test';
}
if (!isset($currentUser['role'])) {
    $currentUser['role'] = 'Administrateur';
}

// Inclure l'en-tête
include '../includes/header.php';

// Récupération des fournisseurs avec pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$offset = ($page - 1) * $limit;


// Récupération des fournisseurs avec pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$offset = ($page - 1) * $limit;

// Filtres
$search = isset($_GET['search']) ? $_GET['search'] : '';
$status = isset($_GET['status']) ? $_GET['status'] : '';
$delai = isset($_GET['delai']) ? $_GET['delai'] : '';

// Construction de la requête avec filtres
$whereClause = [];
$params = [];

if (!empty($search)) {
    $whereClause[] = "(Code_Fournisseur LIKE :search OR Raison_Sociale LIKE :search OR Ville LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($status !== '') {
    $whereClause[] = "Actif = :status";
    $params[':status'] = $status;
}

if (!empty($delai)) {
    switch ($delai) {
        case 'less5':
            $whereClause[] = "Delai_Livraison_Moyen < 5";
            break;
        case '5to10':
            $whereClause[] = "Delai_Livraison_Moyen BETWEEN 5 AND 10";
            break;
        case 'more10':
            $whereClause[] = "Delai_Livraison_Moyen > 10";
            break;
    }
}

$whereString = !empty($whereClause) ? 'WHERE ' . implode(' AND ', $whereClause) : '';

// Récupération des fournisseurs
$fournisseurs = [];
$totalFournisseurs = 0;

try {
    // Compter le nombre total de fournisseurs pour la pagination
    $countQuery = "SELECT COUNT(*) FROM fournisseurs $whereString";
    $countStmt = $db->prepare($countQuery);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalFournisseurs = $countStmt->fetchColumn();
    
    // Récupérer les fournisseurs avec pagination
    $query = "SELECT * FROM fournisseurs $whereString ORDER BY Raison_Sociale LIMIT :limit OFFSET :offset";
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $fournisseurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $error = 'Erreur lors de la récupération des fournisseurs: ' . $e->getMessage();
    error_log($error);
}

// Statistiques des fournisseurs
$nombreFournisseurs = $totalFournisseurs;

try {
    // Fournisseurs actifs
    $queryActifs = "SELECT COUNT(*) FROM fournisseurs WHERE Actif = 1";
    $stmtActifs = $db->query($queryActifs);
    $fournisseursActifs = $stmtActifs->fetchColumn();
    
    // Fournisseurs inactifs
    $queryInactifs = "SELECT COUNT(*) FROM fournisseurs WHERE Actif = 0";
    $stmtInactifs = $db->query($queryInactifs);
    $fournisseursInactifs = $stmtInactifs->fetchColumn();
    
    // Délai moyen de livraison
    $queryDelai = "SELECT AVG(Delai_Livraison_Moyen) FROM fournisseurs WHERE Delai_Livraison_Moyen IS NOT NULL AND Delai_Livraison_Moyen > 0";
    $stmtDelai = $db->query($queryDelai);
    $delaiLivraisonMoyen = round($stmtDelai->fetchColumn());
} catch (PDOException $e) {
    error_log('Erreur lors du calcul des statistiques: ' . $e->getMessage());
    $fournisseursActifs = 0;
    $fournisseursInactifs = 0;
    $delaiLivraisonMoyen = 0;
}

// Calcul de la pagination
$totalPages = ceil($totalFournisseurs / $limit);
?>

<div class="flex h-screen bg-gray-100">
    <!-- Sidebar -->
    <?php include '../includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 overflow-y-auto">
        <!-- Top header -->
        <div class="bg-white shadow-sm">
            <div class="container mx-auto px-6 py-4 flex justify-between items-center">
                <h1 class="text-2xl font-semibold text-gray-800">Gestion des Fournisseurs</h1>
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <span class="text-gray-700"><?php echo htmlspecialchars($currentUser['prenom'] . ' ' . $currentUser['nom']); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="container mx-auto px-6 py-8">
            <?php if (isset($error)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                    <p><?php echo $error; ?></p>
                </div>
            <?php endif; ?>
            
            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- Total Fournisseurs Card -->
                <div class="bg-white p-6 rounded-lg shadow-md transition duration-300 hover:shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-700">Total Fournisseurs</h3>
                            <p class="text-3xl font-bold text-gray-800 mt-2">
                                <?php echo $nombreFournisseurs; ?>
                            </p>
                        </div>
                        <div class="bg-blue-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Fournisseurs Actifs Card -->
                <div class="bg-white p-6 rounded-lg shadow-md transition duration-300 hover:shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-700">Fournisseurs actifs</h3>
                            <p class="text-3xl font-bold text-gray-800 mt-2">
                                <?php echo $fournisseursActifs; ?>
                            </p>
                        </div>
                        <div class="bg-green-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Fournisseurs Inactifs Card -->
                <div class="bg-white p-6 rounded-lg shadow-md transition duration-300 hover:shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-700">Fournisseurs inactifs</h3>
                            <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo $fournisseursInactifs; ?></p>
                        </div>
                        <div class="bg-red-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Délai Livraison Moyen Card -->
                <div class="bg-white p-6 rounded-lg shadow-md transition duration-300 hover:shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-700">Délai livraison moyen</h3>
                            <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo $delaiLivraisonMoyen; ?> jours</p>
                        </div>
                        <div class="bg-yellow-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Content Grid -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
                <!-- Quick Actions Card -->
                <div class="bg-white rounded-lg shadow-md p-6">
                    <h3 class="text-xl font-semibold text-gray-800 mb-4">Actions rapides</h3>
                    <div class="space-y-4">
                        <a href="#" onclick="openModal('addFournisseurModal'); return false;" class="flex items-center p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition duration-200">
                            <div class="bg-blue-100 p-2 rounded-full mr-4">
                                <svg class="w-6 h-6 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                </svg>
                            </div>
                            <div>
                                <h4 class="font-medium text-gray-800">Ajouter un fournisseur</h4>
                                <p class="text-sm text-gray-600">Enregistrer un nouveau fournisseur</p>
                            </div>
                        </a>
                        <a href="export.php" class="flex items-center p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition duration-200">
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
                        <a href="print.php" class="flex items-center p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition duration-200">
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

                <!-- Fournisseurs à contacter Card -->
                <div class="bg-white rounded-lg shadow-md p-6 col-span-2">
                    <h3 class="text-xl font-semibold text-gray-800 mb-4">Fournisseurs à contacter</h3>
                    <?php
                    // Récupérer les fournisseurs à contacter (exemple)
                    try {
                        $queryContact = "SELECT * FROM fournisseurs WHERE Actif = 1 ORDER BY Date_Creation DESC LIMIT 3";
                        $stmtContact = $db->query($queryContact);
                        $fournisseursContact = $stmtContact->fetchAll(PDO::FETCH_ASSOC);
                    } catch (PDOException $e) {
                        $fournisseursContact = [];
                    }
                    ?>
                    
                    <div class="space-y-4">
                        <?php if (!empty($fournisseursContact)): ?>
                            <?php foreach ($fournisseursContact as $index => $fournisseur): ?>
                                <?php 
                                $bgColors = ['yellow', 'blue', 'green'];
                                $bgColor = $bgColors[$index % count($bgColors)];
                                $titles = ['Renouvellement de contrat', 'Négociations en cours', 'Nouveaux catalogues'];
                                $descriptions = [
                                    'Contrat arrivant à échéance prochainement',
                                    'Discussion tarifaire en cours',
                                    'Mise à jour du catalogue disponible'
                                ];
                                ?>
                                <div class="flex items-center p-3 bg-<?= $bgColor ?>-50 rounded-lg">
                                    <div class="bg-<?= $bgColor ?>-100 p-2 rounded-full mr-4">
                                        <svg class="w-6 h-6 text-<?= $bgColor ?>-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                    </div>
                                    <div class="flex-1">
                                        <h4 class="font-medium text-gray-800"><?= $titles[$index % count($titles)] ?></h4>
                                        <p class="text-sm text-gray-600"><?= htmlspecialchars($fournisseur['Raison_Sociale']) ?> - <?= $descriptions[$index % count($descriptions)] ?></p>
                                    </div>
                                    <a href="#" onclick="viewFournisseur(<?= $fournisseur['ID_Fournisseur'] ?>); return false;" class="px-3 py-1 bg-<?= $bgColor ?>-100 text-<?= $bgColor ?>-700 rounded-md text-sm font-medium hover:bg-<?= $bgColor ?>-200 transition duration-200">Voir</a>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-gray-500 text-center py-4">Aucun fournisseur à contacter actuellement</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Fournisseurs List -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-semibold text-gray-800">Liste des fournisseurs</h3>
                    <a href="#" onclick="openModal('addFournisseurModal'); return false;" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Ajouter un fournisseur
                    </a>
                </div>

                <!-- Search and Filter -->
                <form action="" method="GET" class="flex flex-col md:flex-row gap-4 mb-6">
                    <div class="relative flex-1">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="pl-10 pr-4 py-2 w-full border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Rechercher un fournisseur...">
                    </div>
                    <div class="flex gap-4">
                        <select name="status" class="px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Tous les statuts</option>
                            <option value="1" <?= $status === '1' ? 'selected' : '' ?>>Actif</option>
                            <option value="0" <?= $status === '0' ? 'selected' : '' ?>>Inactif</option>
                        </select>
                        <select name="delai" class="px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Tous les délais</option>
                            <option value="less5" <?= $delai === 'less5' ? 'selected' : '' ?>>< 5 jours</option>
                            <option value="5to10" <?= $delai === '5to10' ? 'selected' : '' ?>>5-10 jours</option>
                            <option value="more10" <?= $delai === 'more10' ? 'selected' : '' ?>>> 10 jours</option>
                        </select>
                        <button type="submit" class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                            Filtrer
                        </button>
                    </div>
                </form>

                <!-- Table -->
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Raison Sociale</th>
                                <th scope="col" class="hidden md:table-cell px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ville</th>
                                <th scope="col" class="hidden md:table-cell px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Contact</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Délai (jours)</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($fournisseurs as $fournisseur): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($fournisseur['Code_Fournisseur']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($fournisseur['Raison_Sociale']); ?></td>
                                    <td class="hidden md:table-cell px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($fournisseur['Ville'] ?? ''); ?></td>
                                    <td class="hidden md:table-cell px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($fournisseur['Contact_Principal'] ?? ''); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo $fournisseur['Delai_Livraison_Moyen'] ?? '-'; ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $statusClass = $fournisseur['Actif'] == 1 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800';
                                        $statusText = $fournisseur['Actif'] == 1 ? 'Actif' : 'Inactif';
                                        ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $statusClass; ?>">
                                            <?php echo $statusText; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <button onclick="viewFournisseur(<?php echo $fournisseur['ID_Fournisseur']; ?>)" class="text-blue-600 hover:text-blue-900" title="Voir les détails">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                </svg>
                                            </button>
                                            <button onclick="editFournisseur(<?php echo $fournisseur['ID_Fournisseur']; ?>)" class="text-indigo-600 hover:text-indigo-900" title="Modifier">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                </svg>
                                            </button>
                                            <button onclick="deleteFournisseur(<?php echo $fournisseur['ID_Fournisseur']; ?>)" class="text-red-600 hover:text-red-900" title="Supprimer">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <?php if (empty($fournisseurs)): ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-500">Aucun fournisseur trouvé</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="flex justify-between items-center mt-6">
                    <div class="text-sm text-gray-500">
                        Affichage de <span class="font-medium"><?= $offset + 1 ?></span> à <span class="font-medium"><?= min($offset + $limit, $totalFournisseurs) ?></span> sur <span class="font-medium"><?= $totalFournisseurs ?></span> résultats
                    </div>
                    <div class="flex space-x-1">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page - 1 ?>&limit=<?= $limit ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&delai=<?= $delai ?>" class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                Précédent
                            </a>
                        <?php endif; ?>
                        
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <a href="?page=<?= $i ?>&limit=<?= $limit ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&delai=<?= $delai ?>" class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium <?= $i == $page ? 'text-white bg-blue-500 hover:bg-blue-600' : 'text-gray-700 bg-white hover:bg-gray-50' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?= $page + 1 ?>&limit=<?= $limit ?>&search=<?= urlencode($search) ?>&status=<?= $status ?>&delai=<?= $delai ?>" class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                Suivant
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Add Fournisseur Modal -->
<div id="addFournisseurModal" class="fixed inset-0 z-50 overflow-auto bg-black bg-opacity-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-3xl mx-4">
        <div class="flex justify-between items-center p-4 border-b bg-blue-600 rounded-t-lg">
            <h3 class="text-xl font-semibold text-white">Ajouter un fournisseur</h3>
            <button type="button" onclick="closeModal('addFournisseurModal')" class="text-white hover:text-gray-200">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <form id="addFournisseurForm" action="process_fournisseur.php" method="POST" class="p-5">
            <input type="hidden" name="action" value="create">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label for="code_fournisseur" class="block text-sm font-medium text-gray-700 mb-1">Code Fournisseur*</label>
                    <input type="text" id="code_fournisseur" name="code_fournisseur" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" placeholder="FOUR001" required>
                </div>
                <div>
                    <label for="raison_sociale" class="block text-sm font-medium text-gray-700 mb-1">Raison Sociale*</label>
                    <input type="text" id="raison_sociale" name="raison_sociale" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" placeholder="Nom de l'entreprise" required>
                </div>
                <div>
                    <label for="adresse" class="block text-sm font-medium text-gray-700 mb-1">Adresse</label>
                    <input type="text" id="adresse" name="adresse" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" placeholder="123 rue Example">
                </div>
                <div>
                    <label for="code_postal" class="block text-sm font-medium text-gray-700 mb-1">Code Postal</label>
                    <input type="text" id="code_postal" name="code_postal" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" placeholder="75000">
                </div>
                <div>
                    <label for="ville" class="block text-sm font-medium text-gray-700 mb-1">Ville</label>
                    <input type="text" id="ville" name="ville" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" placeholder="Paris">
                </div>
                <div>
                    <label for="telephone" class="block text-sm font-medium text-gray-700 mb-1">Téléphone</label>
                    <input type="tel" id="telephone" name="telephone" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" placeholder="01 23 45 67 89">
                </div>
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" id="email" name="email" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" placeholder="contact@fournisseur.fr">
                </div>
                <div>
                    <label for="contact_principal" class="block text-sm font-medium text-gray-700 mb-1">Contact Principal</label>
                    <input type="text" id="contact_principal" name="contact_principal" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" placeholder="Nom du contact">
                </div>
                <div>
                    <label for="conditions_paiement" class="block text-sm font-medium text-gray-700 mb-1">Conditions de Paiement</label>
                    <select id="conditions_paiement" name="conditions_paiement" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Sélectionner une condition</option>
                        <option value="30 jours">30 jours</option>
                        <option value="45 jours">45 jours</option>
                        <option value="60 jours">60 jours</option>
                        <option value="Paiement à réception">Paiement à réception</option>
                        <option value="Cash">Cash</option>
                    </select>
                </div>
                <div>
                    <label for="delai_livraison" class="block text-sm font-medium text-gray-700 mb-1">Délai Livraison Moyen (jours)</label>
                    <input type="number" id="delai_livraison" name="delai_livraison" min="0" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" placeholder="7">
                </div>
                <div>
                    <label for="actif" class="block text-sm font-medium text-gray-700 mb-1">Statut</label>
                    <select id="actif" name="actif" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="1">Actif</option>
                        <option value="0">Inactif</option>
                    </select>
                </div>
            </div>

            <div class="mt-5 pt-5 border-t border-gray-200 flex justify-end space-x-3">
                <button type="button" onclick="closeModal('addFournisseurModal')" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Annuler
                </button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- View Fournisseur Modal -->
<div id="viewFournisseurModal" class="fixed inset-0 z-50 overflow-auto bg-black bg-opacity-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl mx-4">
        <div class="flex justify-between items-center p-6 border-b">
            <h3 class="text-xl font-semibold text-gray-900">Détails du fournisseur</h3>
            <button type="button" onclick="closeModal('viewFournisseurModal')" class="text-gray-400 hover:text-gray-500">
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
                            <span class="text-sm font-medium text-gray-500">Code Fournisseur:</span>
                            <span id="view_code_fournisseur" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Raison Sociale:</span>
                            <span id="view_raison_sociale" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Adresse:</span>
                            <span id="view_adresse" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Code Postal:</span>
                            <span id="view_code_postal" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Ville:</span>
                            <span id="view_ville" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Statut:</span>
                            <span id="view_statut" class="text-sm">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full"></span>
                            </span>
                        </div>
                    </div>

                    <h4 class="text-lg font-semibold text-blue-600 mt-6 mb-4">Conditions commerciales</h4>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Conditions de paiement:</span>
                            <span id="view_conditions_paiement" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Délai de livraison moyen:</span>
                            <span id="view_delai_livraison" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Date de création:</span>
                            <span id="view_date_creation" class="text-sm text-gray-900"></span>
                        </div>
                    </div>
                </div>
                
                <div>
                    <h4 class="text-lg font-semibold text-blue-600 mb-4">Contact</h4>
                    <div class="space-y-3">
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Contact principal:</span>
                            <span id="view_contact_principal" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Téléphone:</span>
                            <span id="view_telephone" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Email:</span>
                            <span id="view_email" class="text-sm text-gray-900"></span>
                        </div>
                    </div>

                    <h4 class="text-lg font-semibold text-blue-600 mt-6 mb-4">Dernières commandes</h4>
                    <div id="view_commandes" class="bg-gray-50 p-4 rounded-md">
                        <div class="text-center text-gray-500 py-2">Chargement des commandes...</div>
                    </div>
                </div>
            </div>

            <div class="mt-6 pt-6 border-t border-gray-200 flex justify-end space-x-3">
                <button type="button" onclick="closeModal('viewFournisseurModal')" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Fermer
                </button>
                <button type="button" onclick="editFournisseur(document.getElementById('view_id').value)" class="px-4 py-2 bg-indigo-500 text-white rounded-md hover:bg-indigo-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    Modifier
                </button>
                <button type="button" onclick="window.location.href='../commandes/create.php?fournisseur_id=' + document.getElementById('view_id').value" class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    Nouvelle commande
                </button>
            </div>
            <input type="hidden" id="view_id" value="">
        </div>
    </div>
</div>

<!-- Edit Fournisseur Modal -->
<div id="editFournisseurModal" class="fixed inset-0 z-50 overflow-auto bg-black bg-opacity-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl mx-4">
        <div class="flex justify-between items-center p-6 border-b">
            <h3 class="text-xl font-semibold text-gray-900">Modifier le fournisseur</h3>
            <button type="button" onclick="closeModal('editFournisseurModal')" class="text-gray-400 hover:text-gray-500">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <form id="editFournisseurForm" action="process_fournisseur.php" method="POST" class="p-6">
            <input type="hidden" name="action" value="update">
            <input type="hidden" id="edit_id" name="id" value="">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label for="edit_code_fournisseur" class="block text-sm font-medium text-gray-700 mb-1">Code Fournisseur*</label>
                    <input type="text" id="edit_code_fournisseur" name="code_fournisseur" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" required>
                </div>
                <div>
                    <label for="edit_raison_sociale" class="block text-sm font-medium text-gray-700 mb-1">Raison Sociale*</label>
                    <input type="text" id="edit_raison_sociale" name="raison_sociale" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" required>
                </div>
                <div>
                    <label for="edit_adresse" class="block text-sm font-medium text-gray-700 mb-1">Adresse</label>
                    <input type="text" id="edit_adresse" name="adresse" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label for="edit_code_postal" class="block text-sm font-medium text-gray-700 mb-1">Code Postal</label>
                    <input type="text" id="edit_code_postal" name="code_postal" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label for="edit_ville" class="block text-sm font-medium text-gray-700 mb-1">Ville</label>
                    <input type="text" id="edit_ville" name="ville" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label for="edit_telephone" class="block text-sm font-medium text-gray-700 mb-1">Téléphone</label>
                    <input type="tel" id="edit_telephone" name="telephone" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label for="edit_email" class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" id="edit_email" name="email" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label for="edit_contact_principal" class="block text-sm font-medium text-gray-700 mb-1">Contact Principal</label>
                    <input type="text" id="edit_contact_principal" name="contact_principal" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label for="edit_conditions_paiement" class="block text-sm font-medium text-gray-700 mb-1">Conditions de Paiement</label>
                    <select id="edit_conditions_paiement" name="conditions_paiement" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Sélectionner une condition</option>
                        <option value="30 jours">30 jours</option>
                        <option value="45 jours">45 jours</option>
                        <option value="60 jours">60 jours</option>
                        <option value="Paiement à réception">Paiement à réception</option>
                        <option value="Cash">Cash</option>
                    </select>
                </div>
                <div>
                    <label for="edit_delai_livraison" class="block text-sm font-medium text-gray-700 mb-1">Délai Livraison Moyen (jours)</label>
                    <input type="number" id="edit_delai_livraison" name="delai_livraison" min="0" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label for="edit_actif" class="block text-sm font-medium text-gray-700 mb-1">Statut</label>
                    <select id="edit_actif" name="actif" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="1">Actif</option>
                        <option value="0">Inactif</option>
                    </select>
                </div>
            </div>

            <div class="mt-6 pt-6 border-t border-gray-200 flex justify-end space-x-3">
                <button type="button" onclick="closeModal('editFournisseurModal')" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Annuler
                </button>
                <button type="submit" class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    Enregistrer les modifications
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Fournisseur Confirmation Modal -->
<div id="deleteFournisseurModal" class="fixed inset-0 z-50 overflow-auto bg-black bg-opacity-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-md mx-4">
        <div class="p-6">
            <div class="flex items-center justify-center mb-4">
                <div class="bg-red-100 rounded-full p-3">
                    <svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
            </div>
            <h3 class="text-xl font-semibold text-center text-gray-900 mb-4">Confirmer la suppression</h3>
            <p class="text-gray-700 text-center mb-6">Êtes-vous sûr de vouloir supprimer ce fournisseur ? Cette action est irréversible.</p>
            <div class="flex justify-center space-x-4">
                <button type="button" onclick="closeModal('deleteFournisseurModal')" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Annuler
                </button>
                <form id="deleteFournisseurForm" action="process_fournisseur.php" method="POST">
                    <input type="hidden" name="action" value="delete">
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
        document.body.classList.add('overflow-hidden'); // Prevent scrolling when modal is open
    }

    // Function to close modal
    function closeModal(modalId) {
        document.getElementById(modalId).classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
    }

  
            // Function to view fournisseur details
    function viewFournisseur(id) {
        // Afficher un indicateur de chargement
        document.getElementById('view_commandes').innerHTML = '<div class="text-center text-gray-500 py-2">Chargement des données...</div>';
        
        // Faire une requête pour récupérer les détails du fournisseur depuis la base de données
        fetch(`get_fournisseur.php?id=${id}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Erreur réseau lors de la récupération des données');
                }
                return response.json();
            })
            .then(data => {
                if (data.error) {
                    throw new Error(data.error);
                }
                
                // Stocker l'ID pour une utilisation ultérieure
                document.getElementById('view_id').value = data.fournisseur.ID_Fournisseur;
                
                // Remplir les champs du modal avec les données récupérées
                document.getElementById('view_code_fournisseur').textContent = data.fournisseur.Code_Fournisseur || '-';
                document.getElementById('view_raison_sociale').textContent = data.fournisseur.Raison_Sociale || '-';
                document.getElementById('view_adresse').textContent = data.fournisseur.Adresse || '-';
                document.getElementById('view_code_postal').textContent = data.fournisseur.Code_Postal || '-';
                document.getElementById('view_ville').textContent = data.fournisseur.Ville || '-';
                document.getElementById('view_telephone').textContent = data.fournisseur.Telephone || '-';
                document.getElementById('view_email').textContent = data.fournisseur.Email || '-';
                document.getElementById('view_contact_principal').textContent = data.fournisseur.Contact_Principal || '-';
                document.getElementById('view_conditions_paiement').textContent = data.fournisseur.Conditions_Paiement_Par_Defaut || '-';
                document.getElementById('view_delai_livraison').textContent = data.fournisseur.Delai_Livraison_Moyen ? data.fournisseur.Delai_Livraison_Moyen + ' jours' : '-';
                document.getElementById('view_date_creation').textContent = formatDate(data.fournisseur.Date_Creation);
                
                // Mettre à jour le statut
                const statusSpan = document.getElementById('view_statut').querySelector('span');
                if (data.fournisseur.Actif == 1) {
                    statusSpan.className = 'px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800';
                    statusSpan.textContent = 'Actif';
                } else {
                    statusSpan.className = 'px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800';
                    statusSpan.textContent = 'Inactif';
                }
                
                // Afficher les commandes récentes
                let commandesHtml = '';
                if (data.commandes && data.commandes.length > 0) {
                    commandesHtml = '<div class="space-y-3">';
                    data.commandes.forEach(commande => {
                        commandesHtml += `
                            <div class="flex justify-between">
                                <span class="text-sm font-medium text-gray-500">${commande.Numero_Commande}:</span>
                                <span class="text-sm text-gray-900">${formatDate(commande.Date_Commande)} - ${formatMontant(commande.Montant_Total_TTC)} DH</span>
                            </div>
                        `;
                    });
                    commandesHtml += '</div>';
                } else {
                    commandesHtml = '<div class="text-center text-gray-500 py-2">Aucune commande récente</div>';
                }
                document.getElementById('view_commandes').innerHTML = commandesHtml;
                
                // Afficher le modal
                openModal('viewFournisseurModal');
            })
            .catch(error => {
                console.error('Erreur:', error);
                // Afficher un message d'erreur
                alert('Erreur lors de la récupération des données du fournisseur: ' + error.message);
            });
    }

    // Function to edit fournisseur
    function editFournisseur(id) {
        // Faire une requête pour récupérer les détails du fournisseur depuis la base de données
        fetch(`get_fournisseur.php?id=${id}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Erreur réseau lors de la récupération des données');
                }
                return response.json();
            })
            .then(data => {
                if (data.error) {
                    throw new Error(data.error);
                }
                
                // Remplir les champs du formulaire avec les données récupérées
                document.getElementById('edit_id').value = data.fournisseur.ID_Fournisseur;
                document.getElementById('edit_code_fournisseur').value = data.fournisseur.Code_Fournisseur || '';
                document.getElementById('edit_raison_sociale').value = data.fournisseur.Raison_Sociale || '';
                document.getElementById('edit_adresse').value = data.fournisseur.Adresse || '';
                document.getElementById('edit_code_postal').value = data.fournisseur.Code_Postal || '';
                document.getElementById('edit_ville').value = data.fournisseur.Ville || '';
                document.getElementById('edit_telephone').value = data.fournisseur.Telephone || '';
                document.getElementById('edit_email').value = data.fournisseur.Email || '';
                document.getElementById('edit_contact_principal').value = data.fournisseur.Contact_Principal || '';
                document.getElementById('edit_conditions_paiement').value = data.fournisseur.Conditions_Paiement_Par_Defaut || '';
                document.getElementById('edit_delai_livraison').value = data.fournisseur.Delai_Livraison_Moyen || '';
                document.getElementById('edit_actif').value = data.fournisseur.Actif;
                
                // Fermer le modal de visualisation s'il est ouvert
                closeModal('viewFournisseurModal');
                
                // Afficher le modal d'édition
                openModal('editFournisseurModal');
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Erreur lors de la récupération des données du fournisseur: ' + error.message);
            });
    }

    // Function to delete fournisseur
    function deleteFournisseur(id) {
        document.getElementById('delete_id').value = id;
        openModal('deleteFournisseurModal');
    }

    // Format date function
    function formatDate(dateString) {
        if (!dateString) return '-';
        const date = new Date(dateString);
        return date.toLocaleDateString('fr-FR');
    }
    
    // Format montant function
    function formatMontant(montant) {
        if (montant === null || montant === undefined) return '-';
        return parseFloat(montant).toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    // Close modals when clicking outside
    window.onclick = function(event) {
        const modals = document.querySelectorAll('.fixed.inset-0');
        modals.forEach(modal => {
            if (event.target === modal) {
                modal.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
            }
        });
    }

    // Validation du formulaire d'ajout
    document.getElementById('addFournisseurForm').addEventListener('submit', function(event) {
        const codeInput = document.getElementById('code_fournisseur');
        const raisonInput = document.getElementById('raison_sociale');
        
        let isValid = true;
        
        // Validation du code fournisseur (alphanumeric, max 50 chars)
        if (!/^[a-zA-Z0-9-_]{1,50}$/.test(codeInput.value)) {
            codeInput.classList.add('border-red-500');
            isValid = false;
            alert('Le code fournisseur doit contenir uniquement des lettres, chiffres, tirets ou underscores (max 50 caractères)');
        } else {
            codeInput.classList.remove('border-red-500');
        }
        
        // Validation de la raison sociale (non vide, max 100 chars)
        if (raisonInput.value.trim() === '' || raisonInput.value.length > 100) {
            raisonInput.classList.add('border-red-500');
            isValid = false;
            alert('La raison sociale est requise et ne doit pas dépasser 100 caractères');
        } else {
            raisonInput.classList.remove('border-red-500');
        }
        
        if (!isValid) {
            event.preventDefault();
        }
    });

    // Validation du formulaire d'édition
    document.getElementById('editFournisseurForm').addEventListener('submit', function(event) {
        const codeInput = document.getElementById('edit_code_fournisseur');
        const raisonInput = document.getElementById('edit_raison_sociale');
        
        let isValid = true;
        
        // Validation du code fournisseur (alphanumeric, max 50 chars)
        if (!/^[a-zA-Z0-9-_]{1,50}$/.test(codeInput.value)) {
            codeInput.classList.add('border-red-500');
            isValid = false;
            alert('Le code fournisseur doit contenir uniquement des lettres, chiffres, tirets ou underscores (max 50 caractères)');
        } else {
            codeInput.classList.remove('border-red-500');
        }
        
        // Validation de la raison sociale (non vide, max 100 chars)
        if (raisonInput.value.trim() === '' || raisonInput.value.length > 100) {
            raisonInput.classList.add('border-red-500');
            isValid = false;
            alert('La raison sociale est requise et ne doit pas dépasser 100 caractères');
        } else {
            raisonInput.classList.remove('border-red-500');
        }
        
        if (!isValid) {
            event.preventDefault();
        }
    });
</script>

<?php include '../includes/footer.php'; ?>

