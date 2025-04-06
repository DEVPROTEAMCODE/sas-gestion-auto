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
// Récupérer les informations de l'utilisateur actuel
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







// Récupération des offres avec pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
$offset = ($page - 1) * $limit;

// Filtres
$search = isset($_GET['search']) ? $_GET['search'] : '';
$categorie = isset($_GET['categorie']) ? $_GET['categorie'] : '';
$statut = isset($_GET['statut']) ? $_GET['statut'] : '';
$date_debut = isset($_GET['date_debut']) ? $_GET['date_debut'] : '';
$date_fin = isset($_GET['date_fin']) ? $_GET['date_fin'] : '';

// Construction de la requête avec filtres
$whereClause = [];
$params = [];

if (!empty($search)) {
    $whereClause[] = "(code LIKE :search OR nom LIKE :search OR description LIKE :search)";
    $params[':search'] = "%$search%";
}

if (!empty($categorie)) {
    $whereClause[] = "categorie_id = :categorie";
    $params[':categorie'] = $categorie;
}

if ($statut !== '') {
    $whereClause[] = "actif = :statut";
    $params[':statut'] = $statut;
}

if (!empty($date_debut)) {
    $whereClause[] = "date_debut >= :date_debut";
    $params[':date_debut'] = $date_debut;
}

if (!empty($date_fin)) {
    $whereClause[] = "date_fin <= :date_fin";
    $params[':date_fin'] = $date_fin;
}

$whereString = !empty($whereClause) ? 'WHERE ' . implode(' AND ', $whereClause) : '';

// Récupération des offres
$offres = [];
$totalOffres = 0;

try {
    // Compter le nombre total d'offres pour la pagination
    $countQuery = "SELECT COUNT(*) FROM offres $whereString";
    $countStmt = $db->prepare($countQuery);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalOffres = $countStmt->fetchColumn();
    
    // Récupérer les offres avec pagination
    $query = "SELECT o.*, c.nom as categorie_nom 
              FROM offres o 
              LEFT JOIN categorie c ON o.categorie_id = c.id 
              $whereString 
              ORDER BY o.date_creation DESC 
              LIMIT :limit OFFSET :offset";
    $stmt = $db->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $offres = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $error = 'Erreur lors de la récupération des offres: ' . $e->getMessage();
    error_log($error);
}

// Récupérer les catégories pour le filtre
$categories = [];
try {
    $queryCategories = "SELECT id, nom FROM categorie ORDER BY nom";
    $stmtCategories = $db->query($queryCategories);
    $categories = $stmtCategories->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Erreur lors de la récupération des catégories: ' . $e->getMessage());
}

// Statistiques des offres
try {
    // Offres actives
    $queryActives = "SELECT COUNT(*) FROM offres WHERE actif = 1";
    $stmtActives = $db->query($queryActives);
    $offresActives = $stmtActives->fetchColumn();
    
    // Offres en cours (date actuelle entre date_debut et date_fin)
    $queryEnCours = "SELECT COUNT(*) FROM offres WHERE actif = 1 AND date_debut <= CURDATE() AND (date_fin IS NULL OR date_fin >= CURDATE())";
    $stmtEnCours = $db->query($queryEnCours);
    $offresEnCours = $stmtEnCours->fetchColumn();
    
    // Offres à venir
    $queryAVenir = "SELECT COUNT(*) FROM offres WHERE actif = 1 AND date_debut > CURDATE()";
    $stmtAVenir = $db->query($queryAVenir);
    $offresAVenir = $stmtAVenir->fetchColumn();
    
    // Offres expirées
    $queryExpirees = "SELECT COUNT(*) FROM offres WHERE date_fin < CURDATE()";
    $stmtExpirees = $db->query($queryExpirees);
    $offresExpirees = $stmtExpirees->fetchColumn();
} catch (PDOException $e) {
    error_log('Erreur lors du calcul des statistiques: ' . $e->getMessage());
    $offresActives = 0;
    $offresEnCours = 0;
    $offresAVenir = 0;
    $offresExpirees = 0;
}

// Calcul de la pagination
$totalPages = ceil($totalOffres / $limit);
?>

<div class="flex h-screen bg-gray-100">
    <!-- Sidebar -->
    <?php include '../includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 overflow-y-auto">
        <!-- Top header -->
        <div class="bg-white shadow-sm">
            <div class="container mx-auto px-6 py-4 flex justify-between items-center">
                <h1 class="text-2xl font-semibold text-gray-800">Gestion des Offres</h1>
                <div class="flex items-center space-x-4">
                    <div class="relative">
                    <span class="text-gray-700"><?php echo isset($currentUser['name']) ? htmlspecialchars($currentUser['name']) : 'Utilisateur'; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="container mx-auto px-6 py-8">
            <?php if (isset($_SESSION['flash'])): ?>
                <div class="bg-<?= $_SESSION['flash']['type'] === 'success' ? 'green' : 'red' ?>-100 border-l-4 border-<?= $_SESSION['flash']['type'] === 'success' ? 'green' : 'red' ?>-500 text-<?= $_SESSION['flash']['type'] === 'success' ? 'green' : 'red' ?>-700 p-4 mb-6" role="alert">
                    <p><?php echo $_SESSION['flash']['message']; ?></p>
                </div>
                <?php unset($_SESSION['flash']); ?>
            <?php endif; ?>
            
            <?php if (isset($error)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6" role="alert">
                    <p><?php echo $error; ?></p>
                </div>
            <?php endif; ?>
            
            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <!-- Total Offres Card -->
                <div class="bg-white p-6 rounded-lg shadow-md transition duration-300 hover:shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-700">Total Offres</h3>
                            <p class="text-3xl font-bold text-gray-800 mt-2">
                                <?php echo $totalOffres; ?>
                            </p>
                        </div>
                        <div class="bg-blue-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Offres Actives Card -->
                <div class="bg-white p-6 rounded-lg shadow-md transition duration-300 hover:shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-700">Offres actives</h3>
                            <p class="text-3xl font-bold text-gray-800 mt-2">
                                <?php echo $offresActives; ?>
                            </p>
                        </div>
                        <div class="bg-green-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Offres En Cours Card -->
                <div class="bg-white p-6 rounded-lg shadow-md transition duration-300 hover:shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-700">Offres en cours</h3>
                            <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo $offresEnCours; ?></p>
                        </div>
                        <div class="bg-yellow-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                    </div>
                </div>

                <!-- Offres à venir Card -->
                <div class="bg-white p-6 rounded-lg shadow-md transition duration-300 hover:shadow-lg">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-semibold text-gray-700">Offres à venir</h3>
                            <p class="text-3xl font-bold text-gray-800 mt-2"><?php echo $offresAVenir; ?></p>
                        </div>
                        <div class="bg-purple-100 p-3 rounded-full">
                            <svg class="w-8 h-8 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
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
                        <a href="#" onclick="openModal('addOffreModal'); return false;" class="flex items-center p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition duration-200">
                            <div class="bg-blue-100 p-2 rounded-full mr-4">
                                <svg class="w-6 h-6 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                </svg>
                            </div>
                            <div>
                                <h4 class="font-medium text-gray-800">Ajouter une offre</h4>
                                <p class="text-sm text-gray-600">Créer une nouvelle promotion</p>
                            </div>
                        </a>
                        <a href="export.php" class="flex items-center p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition duration-200">
                            <div class="bg-green-100 p-2 rounded-full mr-4">
                                <svg class="w-6 h-6 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path>
                                </svg>
                            </div>
                            <div>
                                <h4 class="font-medium text-gray-800">Exporter les offres</h4>
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
                                <h4 class="font-medium text-gray-800">Imprimer les offres</h4>
                                <p class="text-sm text-gray-600">Générer un rapport imprimable</p>
                            </div>
                        </a>
                    </div>
                </div>

                <!-- Offres à surveiller Card -->
                <div class="bg-white rounded-lg shadow-md p-6 col-span-2">
                    <h3 class="text-xl font-semibold text-gray-800 mb-4">Offres à surveiller</h3>
                    <?php
                    // Récupérer les offres qui expirent bientôt
                    try {
                        $queryExpiration = "SELECT id, code, nom, date_fin, categorie_id 
                                           FROM offres 
                                           WHERE actif = 1 AND date_fin IS NOT NULL 
                                           AND date_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
                                           ORDER BY date_fin ASC
                                           LIMIT 3";
                        $stmtExpiration = $db->query($queryExpiration);
                        $offresExpiration = $stmtExpiration->fetchAll(PDO::FETCH_ASSOC);
                    } catch (PDOException $e) {
                        $offresExpiration = [];
                    }
                    ?>
                    
                    <div class="space-y-4">
                        <?php if (!empty($offresExpiration)): ?>
                            <?php foreach ($offresExpiration as $index => $offre): ?>
                                <?php 
                                // Récupérer le nom de la catégorie
                                $categorieName = '';
                                if (!empty($offre['categorie_id'])) {
                                    try {
                                        $queryCat = "SELECT nom FROM categorie WHERE id = :id";
                                        $stmtCat = $db->prepare($queryCat);
                                        $stmtCat->bindParam(':id', $offre['categorie_id'], PDO::PARAM_INT);
                                        $stmtCat->execute();
                                        $categorieName = $stmtCat->fetchColumn();
                                    } catch (PDOException $e) {
                                        $categorieName = '';
                                    }
                                }
                                
                                $daysLeft = (new DateTime($offre['date_fin']))->diff(new DateTime())->days;
                                $bgColors = ['yellow', 'orange', 'red'];
                                $bgColor = $bgColors[min($daysLeft, 2)];
                                ?>
                                <div class="flex items-center p-3 bg-<?= $bgColor ?>-50 rounded-lg">
                                    <div class="bg-<?= $bgColor ?>-100 p-2 rounded-full mr-4">
                                        <svg class="w-6 h-6 text-<?= $bgColor ?>-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                        </svg>
                                    </div>
                                    <div class="flex-1">
                                        <h4 class="font-medium text-gray-800"><?= htmlspecialchars($offre['nom']) ?> (<?= htmlspecialchars($offre['code']) ?>)</h4>
                                        <p class="text-sm text-gray-600">
                                            <?= $categorieName ? 'Catégorie: ' . htmlspecialchars($categorieName) . ' - ' : '' ?>
                                            Expire dans <?= $daysLeft ?> jour<?= $daysLeft > 1 ? 's' : '' ?> (<?= date('d/m/Y', strtotime($offre['date_fin'])) ?>)
                                        </p>
                                    </div>
                                    <a href="#" onclick="viewOffre(<?= $offre['id'] ?>); return false;" class="px-3 py-1 bg-<?= $bgColor ?>-100 text-<?= $bgColor ?>-700 rounded-md text-sm font-medium hover:bg-<?= $bgColor ?>-200 transition duration-200">Voir</a>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-gray-500 text-center py-4">Aucune offre n'expire dans les 7 prochains jours</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Offres List -->
            <div class="bg-white rounded-lg shadow-md p-6 mb-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-semibold text-gray-800">Liste des offres</h3>
                    <a href="#" onclick="openModal('addOffreModal'); return false;" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Ajouter une offre
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
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" class="pl-10 pr-4 py-2 w-full border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Rechercher une offre...">
                    </div>
                    <div class="flex flex-wrap gap-4">
                        <select name="categorie" class="px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Toutes les catégories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $categorie == $cat['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="statut" class="px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                            <option value="">Tous les statuts</option>
                            <option value="1" <?= $statut === '1' ? 'selected' : '' ?>>Actif</option>
                            <option value="0" <?= $statut === '0' ? 'selected' : '' ?>>Inactif</option>
                        </select>
                        <div class="flex items-center space-x-2">
                            <label class="text-sm text-gray-600">Du:</label>
                            <input type="date" name="date_debut" value="<?= htmlspecialchars($date_debut) ?>" class="px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <div class="flex items-center space-x-2">
                            <label class="text-sm text-gray-600">Au:</label>
                            <input type="date" name="date_fin" value="<?= htmlspecialchars($date_fin) ?>" class="px-4 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                        </div>
                        <button type="submit" class="px-4 py-2 bg-blue-500 text-white rounded-md hover:bg-blue-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                            Filtrer
                        </button>
                        <a href="index.php" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                            Réinitialiser
                        </a>
                    </div>
                </form>

                <!-- Table -->
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Code</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nom</th>
                                <th scope="col" class="hidden md:table-cell px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Catégorie</th>
                                <th scope="col" class="hidden md:table-cell px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Période</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Remise</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($offres as $offre): ?>
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900"><?php echo htmlspecialchars($offre['code']); ?></td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($offre['nom']); ?></td>
                                    <td class="hidden md:table-cell px-6 py-4 whitespace-nowrap text-sm text-gray-500"><?php echo htmlspecialchars($offre['categorie_nom'] ?? 'Toutes'); ?></td>
                                    <td class="hidden md:table-cell px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php 
                                        echo date('d/m/Y', strtotime($offre['date_debut'])); 
                                        echo $offre['date_fin'] ? ' au ' . date('d/m/Y', strtotime($offre['date_fin'])) : ' (sans fin)';
                                        ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php 
                                        echo htmlspecialchars($offre['valeur_remise']); 
                                        echo $offre['type_remise'] === 'pourcentage' ? ' %' : ' DH';
                                        ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $today = date('Y-m-d');
                                        $isActive = $offre['actif'] == 1;
                                        $isStarted = $offre['date_debut'] <= $today;
                                        $isEnded = $offre['date_fin'] && $offre['date_fin'] < $today;
                                        
                                        if (!$isActive) {
                                            $statusClass = 'bg-gray-100 text-gray-800';
                                            $statusText = 'Inactif';
                                        } elseif (!$isStarted) {
                                            $statusClass = 'bg-purple-100 text-purple-800';
                                            $statusText = 'À venir';
                                        } elseif ($isEnded) {
                                            $statusClass = 'bg-red-100 text-red-800';
                                            $statusText = 'Expiré';
                                        } else {
                                            $statusClass = 'bg-green-100 text-green-800';
                                            $statusText = 'En cours';
                                        }
                                        ?>
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $statusClass; ?>">
                                            <?php echo $statusText; ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-2">
                                            <button onclick="viewOffre(<?php echo $offre['id']; ?>)" class="text-blue-600 hover:text-blue-900" title="Voir les détails">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                </svg>
                                            </button>
                                            <button onclick="editOffre(<?php echo $offre['id']; ?>)" class="text-indigo-600 hover:text-indigo-900" title="Modifier">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                </svg>
                                            </button>
                                            <button onclick="duplicateOffre(<?php echo $offre['id']; ?>)" class="text-green-600 hover:text-green-900" title="Dupliquer">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"></path>
                                                </svg>
                                            </button>
                                            <button onclick="deleteOffre(<?php echo $offre['id']; ?>)" class="text-red-600 hover:text-red-900" title="Supprimer">
                                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                </svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            
                            <?php if (empty($offres)): ?>
                                <tr>
                                    <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-500">Aucune offre trouvée</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                <div class="flex justify-between items-center mt-6">
                    <div class="text-sm text-gray-500">
                        Affichage de <span class="font-medium"><?= $offset + 1 ?></span> à <span class="font-medium"><?= min($offset + $limit, $totalOffres) ?></span> sur <span class="font-medium"><?= $totalOffres ?></span> résultats
                    </div>
                    <div class="flex space-x-1">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page - 1 ?>&limit=<?= $limit ?>&search=<?= urlencode($search) ?>&categorie=<?= $categorie ?>&statut=<?= $statut ?>&date_debut=<?= urlencode($date_debut) ?>&date_fin=<?= urlencode($date_fin) ?>" class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
                                Précédent
                            </a>
                        <?php endif; ?>
                        
                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        
                        for ($i = $startPage; $i <= $endPage; $i++):
                        ?>
                            <a href="?page=<?= $i ?>&limit=<?= $limit ?>&search=<?= urlencode($search) ?>&categorie=<?= $categorie ?>&statut=<?= $statut ?>&date_debut=<?= urlencode($date_debut) ?>&date_fin=<?= urlencode($date_fin) ?>" class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium <?= $i == $page ? 'text-white bg-blue-500 hover:bg-blue-600' : 'text-gray-700 bg-white hover:bg-gray-50' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?= $page + 1 ?>&limit=<?= $limit ?>&search=<?= urlencode($search) ?>&categorie=<?= $categorie ?>&statut=<?= $statut ?>&date_debut=<?= urlencode($date_debut) ?>&date_fin=<?= urlencode($date_fin) ?>" class="px-3 py-1 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50">
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

<!-- Add Offre Modal -->
<div id="addOffreModal" class="fixed inset-0 z-50 overflow-auto bg-black bg-opacity-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl mx-4">
        <div class="flex justify-between items-center p-4 border-b bg-blue-600 rounded-t-lg">
            <h3 class="text-xl font-semibold text-white">Ajouter une offre</h3>
            <button type="button" onclick="closeModal('addOffreModal')" class="text-white hover:text-gray-200">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <form id="addOffreForm" action="process_offre.php" method="POST" class="p-5" enctype="multipart/form-data">
            <input type="hidden" name="action" value="create">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label for="code" class="block text-sm font-medium text-gray-700 mb-1">Code*</label>
                    <input type="text" id="code" name="code" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" placeholder="OFF001" required>
                </div>
                <div>
                    <label for="nom" class="block text-sm font-medium text-gray-700 mb-1">Nom*</label>
                    <input type="text" id="nom" name="nom" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" placeholder="Soldes d'été" required>
                </div>
                <div class="md:col-span-2">
                    <label for="description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea id="description" name="description" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" placeholder="Description détaillée de l'offre"></textarea>
                </div>
                <div>
                    <label for="categorie_id" class="block text-sm font-medium text-gray-700 mb-1">Catégorie</label>
                    <select id="categorie_id" name="categorie_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Toutes les catégories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="priorite" class="block text-sm font-medium text-gray-700 mb-1">Priorité</label>
                    <input type="number" id="priorite" name="priorite" min="0" max="100" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" placeholder="0" value="0">
                    <p class="text-xs text-gray-500 mt-1">Plus la priorité est élevée, plus l'offre sera appliquée en premier</p>
                </div>
                <div>
                    <label for="date_debut" class="block text-sm font-medium text-gray-700 mb-1">Date de début*</label>
                    <input type="date" id="date_debut" name="date_debut" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" required>
                </div>
                <div>
                    <label for="date_fin" class="block text-sm font-medium text-gray-700 mb-1">Date de fin</label>
                    <input type="date" id="date_fin" name="date_fin" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-xs text-gray-500 mt-1">Laissez vide pour une offre sans date de fin</p>
                </div>
                <div>
                    <label for="type_remise" class="block text-sm font-medium text-gray-700 mb-1">Type de remise*</label>
                    <select id="type_remise" name="type_remise" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" required>
                        <option value="pourcentage">Pourcentage (%)</option>
                        <option value="montant_fixe">Montant fixe (DH)</option>
                    </select>
                </div>
                <div>
                    <label for="valeur_remise" class="block text-sm font-medium text-gray-700 mb-1">Valeur de la remise*</label>
                    <input type="number" id="valeur_remise" name="valeur_remise" step="0.01" min="0" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" placeholder="10.00" required>
                </div>
                <div>
                    <label for="image" class="block text-sm font-medium text-gray-700 mb-1">Image</label>
                    <input type="file" id="image" name="image" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" accept="image/*">
                </div>
                <div>
                    <label for="actif" class="block text-sm font-medium text-gray-700 mb-1">Statut</label>
                    <select id="actif" name="actif" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="1">Actif</option>
                        <option value="0">Inactif</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label for="conditions" class="block text-sm font-medium text-gray-700 mb-1">Conditions</label>
                    <textarea id="conditions" name="conditions" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" placeholder="Conditions particulières de l'offre"></textarea>
                </div>
            </div>

            <div class="mt-5 pt-5 border-t border-gray-200">
                <h4 class="text-lg font-semibold text-gray-800 mb-3">Articles concernés</h4>
                <p class="text-sm text-gray-600 mb-3">Sélectionnez les articles spécifiques pour cette offre ou laissez vide pour appliquer à tous les articles de la catégorie.</p>
                
                <div class="mb-4">
                    <div class="flex items-center mb-2">
                        <input type="checkbox" id="select_all_articles" class="mr-2">
                        <label for="select_all_articles" class="text-sm font-medium text-gray-700">Sélectionner tous les articles</label>
                    </div>
                    
                    <div id="articles_container" class="max-h-60 overflow-y-auto border border-gray-300 rounded-md p-3">
                        <div class="text-center text-gray-500 py-2">Chargement des articles...</div>
                    </div>
                </div>
            </div>

            <div class="mt-5 pt-5 border-t border-gray-200 flex justify-end space-x-3">
                <button type="button" onclick="closeModal('addOffreModal')" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Annuler
                </button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>

<!-- View Offre Modal -->
<div id="viewOffreModal" class="fixed inset-0 z-50 overflow-auto bg-black bg-opacity-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl mx-4">
        <div class="flex justify-between items-center p-6 border-b">
            <h3 class="text-xl font-semibold text-gray-900">Détails de l'offre</h3>
            <button type="button" onclick="closeModal('viewOffreModal')" class="text-gray-400 hover:text-gray-500">
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
                            <span class="text-sm font-medium text-gray-500">Code:</span>
                            <span id="view_code" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Nom:</span>
                            <span id="view_nom" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Catégorie:</span>
                            <span id="view_categorie" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Période:</span>
                            <span id="view_periode" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Remise:</span>
                            <span id="view_remise" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Priorité:</span>
                            <span id="view_priorite" class="text-sm text-gray-900"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Statut:</span>
                            <span id="view_statut" class="text-sm">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full"></span>
                            </span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-sm font-medium text-gray-500">Date de création:</span>
                            <span id="view_date_creation" class="text-sm text-gray-900"></span>
                        </div>
                    </div>

                    <h4 class="text-lg font-semibold text-blue-600 mt-6 mb-4">Description</h4>
                    <div class="bg-gray-50 p-3 rounded-md">
                        <p id="view_description" class="text-sm text-gray-700"></p>
                    </div>

                    <h4 class="text-lg font-semibold text-blue-600 mt-6 mb-4">Conditions</h4>
                    <div class="bg-gray-50 p-3 rounded-md">
                        <p id="view_conditions" class="text-sm text-gray-700"></p>
                    </div>
                </div>
                
                <div>
                    <h4 class="text-lg font-semibold text-blue-600 mb-4">Image</h4>
                    <div id="view_image_container" class="bg-gray-50 p-3 rounded-md flex items-center justify-center h-40 mb-6">
                        <img id="view_image" src="" alt="Image de l'offre" class="max-h-full max-w-full hidden">
                        <span id="view_no_image" class="text-gray-500">Aucune image</span>
                    </div>

                    <h4 class="text-lg font-semibold text-blue-600 mb-4">Articles concernés</h4>
                    <div id="view_articles" class="bg-gray-50 p-3 rounded-md max-h-60 overflow-y-auto">
                        <div class="text-center text-gray-500 py-2">Chargement des articles...</div>
                    </div>
                </div>
            </div>

            <div class="mt-6 pt-6 border-t border-gray-200 flex justify-end space-x-3">
                <button type="button" onclick="closeModal('viewOffreModal')" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Fermer
                </button>
                <button type="button" id="edit_offre_button" class="px-4 py-2 bg-indigo-500 text-white rounded-md hover:bg-indigo-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                    Modifier
                </button>
                <button type="button" id="duplicate_offre_button" class="px-4 py-2 bg-green-500 text-white rounded-md hover:bg-green-600 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
                    Dupliquer
                </button>
            </div>
            <input type="hidden" id="view_id" value="">
        </div>
    </div>
</div>

<!-- Edit Offre Modal -->
<div id="editOffreModal" class="fixed inset-0 z-50 overflow-auto bg-black bg-opacity-50 flex items-center justify-center hidden">
    <div class="bg-white rounded-lg shadow-xl w-full max-w-4xl mx-4">
        <div class="flex justify-between items-center p-6 border-b">
            <h3 class="text-xl font-semibold text-gray-900">Modifier l'offre</h3>
            <button type="button" onclick="closeModal('editOffreModal')" class="text-gray-400 hover:text-gray-500">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>

        <form id="editOffreForm" action="process_offre.php" method="POST" class="p-6" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update">
            <input type="hidden" id="edit_id" name="id" value="">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label for="edit_code" class="block text-sm font-medium text-gray-700 mb-1">Code*</label>
                    <input type="text" id="edit_code" name="code" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" required>
                </div>
                <div>
                    <label for="edit_nom" class="block text-sm font-medium text-gray-700 mb-1">Nom*</label>
                    <input type="text" id="edit_nom" name="nom" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" required>
                </div>
                <div class="md:col-span-2">
                    <label for="edit_description" class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                    <textarea id="edit_description" name="description" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500"></textarea>
                </div>
                <div>
                    <label for="edit_categorie_id" class="block text-sm font-medium text-gray-700 mb-1">Catégorie</label>
                    <select id="edit_categorie_id" name="categorie_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="">Toutes les catégories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="edit_priorite" class="block text-sm font-medium text-gray-700 mb-1">Priorité</label>
                    <input type="number" id="edit_priorite" name="priorite" min="0" max="100" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-xs text-gray-500 mt-1">Plus la priorité est élevée, plus l'offre sera appliquée en premier</p>
                </div>
                <div>
                    <label for="edit_date_debut" class="block text-sm font-medium text-gray-700 mb-1">Date de début*</label>
                    <input type="date" id="edit_date_debut" name="date_debut" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" required>
                </div>
                <div>
                    <label for="edit_date_fin" class="block text-sm font-medium text-gray-700 mb-1">Date de fin</label>
                    <input type="date" id="edit_date_fin" name="date_fin" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                    <p class="text-xs text-gray-500 mt-1">Laissez vide pour une offre sans date de fin</p>
                </div>
                <div>
                    <label for="edit_type_remise" class="block text-sm font-medium text-gray-700 mb-1">Type de remise*</label>
                    <select id="edit_type_remise" name="type_remise" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" required>
                        <option value="pourcentage">Pourcentage (%)</option>
                        <option value="montant_fixe">Montant fixe (DH)</option>
                    </select>
                </div>
                <div>
                    <label for="edit_valeur_remise" class="block text-sm font-medium text-gray-700 mb-1">Valeur de la remise*</label>
                    <input type="number" id="edit_valeur_remise" name="valeur_remise" step="0.01" min="0" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" required>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Image actuelle</label>
                    <div class="flex items-center">
                        <div id="edit_current_image_container" class="h-16 w-16 bg-gray-100 rounded-md flex items-center justify-center mr-3">
                            <img id="edit_current_image" src="" alt="Image actuelle" class="max-h-full max-w-full hidden">
                            <span id="edit_no_current_image" class="text-xs text-gray-500">Aucune image</span>
                        </div>
                        <div class="flex items-center">
                            <input type="checkbox" id="edit_delete_image" name="delete_image" class="mr-2">
                            <label for="edit_delete_image" class="text-sm text-gray-700">Supprimer l'image</label>
                        </div>
                    </div>
                </div>
                <div>
                    <label for="edit_image" class="block text-sm font-medium text-gray-700 mb-1">Nouvelle image</label>
                    <input type="file" id="edit_image" name="image" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500" accept="image/*">
                </div>
                <div>
                    <label for="edit_actif" class="block text-sm font-medium text-gray-700 mb-1">Statut</label>
                    <select id="edit_actif" name="actif" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500">
                        <option value="1">Actif</option>
                        <option value="0">Inactif</option>
                    </select>
                </div>
                <div class="md:col-span-2">
                    <label for="edit_conditions" class="block text-sm font-medium text-gray-700 mb-1">Conditions</label>
                    <textarea id="edit_conditions" name="conditions" rows="2" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-blue-500 focus:border-blue-500"></textarea>
                </div>
            </div>

            <div class="mt-5 pt-5 border-t border-gray-200">
                <h4 class="text-lg font-semibold text-gray-800 mb-3">Articles concernés</h4>
                <p class="text-sm text-gray-600 mb-3">Sélectionnez les articles spécifiques pour cette offre ou laissez vide pour appliquer à tous les articles de la catégorie.</p>
                
                <div class="mb-4">
                    <div class="flex items-center mb-2">
                        <input type="checkbox" id="edit_select_all_articles" class="mr-2">
                        <label for="edit_select_all_articles" class="text-sm font-medium text-gray-700">Sélectionner tous les articles</label>
                    </div>
                    
                    <div id="edit_articles_container" class="max-h-60 overflow-y-auto border border-gray-300 rounded-md p-3">
                        <div class="text-center text-gray-500 py-2">Chargement des articles...</div>
                    </div>
                </div>
            </div>

            <div class="mt-5 pt-5 border-t border-gray-200 flex justify-end space-x-3">
                <button type="button" onclick="closeModal('editOffreModal')" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Annuler
                </button>
                <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                    Enregistrer les modifications
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Offre Confirmation Modal -->
<div id="deleteOffreModal" class="fixed inset-0 z-50 overflow-auto bg-black bg-opacity-50 flex items-center justify-center hidden">
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
            <p class="text-gray-700 text-center mb-6">Êtes-vous sûr de vouloir supprimer cette offre ? Cette action est irréversible.</p>
            <div class="flex justify-center space-x-4">
                <button type="button" onclick="closeModal('deleteOffreModal')" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2">
                    Annuler
                </button>
                <form id="deleteOffreForm" action="process_offre.php" method="POST">
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

    // Function to load articles for a category
    function loadArticles(categoryId, containerId, selectedArticles = []) {
        const container = document.getElementById(containerId);
        container.innerHTML = '<div class="text-center text-gray-500 py-2">Chargement des articles...</div>';
        
        // Fetch articles from the server
        fetch(`get_articles.php?categorie_id=${categoryId || ''}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Erreur réseau lors de la récupération des articles');
                }
                return response.json();
            })
            .then(data => {
                if (data.error) {
                    throw new Error(data.error);
                }
                
                if (data.articles && data.articles.length > 0) {
                    let html = '<div class="space-y-2">';
                    data.articles.forEach(article => {
                        const isChecked = selectedArticles.includes(article.id) ? 'checked' : '';
                        html += `
                            <div class="flex items-center">
                                <input type="checkbox" name="articles[]" value="${article.id}" id="article_${article.id}" ${isChecked} class="mr-2 article-checkbox">
                                <label for="article_${article.id}" class="text-sm text-gray-700">${article.reference} - ${article.designation} (${article.prix_vente} DH)</label>
                            </div>
                        `;
                    });
                    html += '</div>';
                    container.innerHTML = html;
                    
                    // Add event listeners to checkboxes
                    const checkboxes = container.querySelectorAll('.article-checkbox');
                    const selectAllCheckbox = document.getElementById(containerId === 'articles_container' ? 'select_all_articles' : 'edit_select_all_articles');
                    
                    selectAllCheckbox.addEventListener('change', function() {
                        checkboxes.forEach(checkbox => {
                            checkbox.checked = this.checked;
                        });
                    });
                    
                    // Update "select all" checkbox state based on individual checkboxes
                    function updateSelectAllCheckbox() {
                        const allChecked = Array.from(checkboxes).every(checkbox => checkbox.checked);
                        const someChecked = Array.from(checkboxes).some(checkbox => checkbox.checked);
                        
                        selectAllCheckbox.checked = allChecked;
                        selectAllCheckbox.indeterminate = someChecked && !allChecked;
                    }
                    
                    checkboxes.forEach(checkbox => {
                        checkbox.addEventListener('change', updateSelectAllCheckbox);
                    });
                    
                    // Initial state
                    updateSelectAllCheckbox();
                } else {
                    container.innerHTML = '<div class="text-center text-gray-500 py-2">Aucun article trouvé pour cette catégorie</div>';
                }
            })
            .catch(error => {
                console.error('Erreur:', error);
                container.innerHTML = `<div class="text-center text-red-500 py-2">Erreur: ${error.message}</div>`;
            });
    }

    // Load articles when category changes in add form
    document.getElementById('categorie_id').addEventListener('change', function() {
        loadArticles(this.value, 'articles_container');
    });
    
    // Load articles when category changes in edit form
    document.getElementById('edit_categorie_id').addEventListener('change', function() {
        loadArticles(this.value, 'edit_articles_container', window.selectedArticles || []);
    });

    // Function to view offre details
    function viewOffre(id) {
        // Display loading indicator
        document.getElementById('view_articles').innerHTML = '<div class="text-center text-gray-500 py-2">Chargement des données...</div>';
        
        // Fetch offre details from the server
        fetch(`get_offre.php?id=${id}`)
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
                
                // Store the ID for later use
                document.getElementById('view_id').value = data.offre.id;
                
                // Fill the modal with retrieved data
                document.getElementById('view_code').textContent = data.offre.code || '-';
                document.getElementById('view_nom').textContent = data.offre.nom || '-';
                document.getElementById('view_categorie').textContent = data.offre.categorie_nom || 'Toutes les catégories';
                
                // Format period
                let periode = formatDate(data.offre.date_debut);
                periode += data.offre.date_fin ? ' au ' + formatDate(data.offre.date_fin) : ' (sans date de fin)';
                document.getElementById('view_periode').textContent = periode;
                
                // Format remise
                const remise = data.offre.valeur_remise + (data.offre.type_remise === 'pourcentage' ? ' %' : ' DH');
                document.getElementById('view_remise').textContent = remise;
                
                document.getElementById('view_priorite').textContent = data.offre.priorite || '0';
                document.getElementById('view_description').textContent = data.offre.description || 'Aucune description';
                document.getElementById('view_conditions').textContent = data.offre.conditions || 'Aucune condition particulière';
                document.getElementById('view_date_creation').textContent = formatDate(data.offre.date_creation);
                
                // Update status
                const statusSpan = document.getElementById('view_statut').querySelector('span');
                const today = new Date().toISOString().split('T')[0];
                const isActive = data.offre.actif == 1;
                const isStarted = data.offre.date_debut <= today;
                const isEnded = data.offre.date_fin && data.offre.date_fin < today;
                
                if (!isActive) {
                    statusSpan.className = 'px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800';
                    statusSpan.textContent = 'Inactif';
                } else if (!isStarted) {
                    statusSpan.className = 'px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-purple-100 text-purple-800';
                    statusSpan.textContent = 'À venir';
                } else if (isEnded) {
                    statusSpan.className = 'px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800';
                    statusSpan.textContent = 'Expiré';
                } else {
                    statusSpan.className = 'px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800';
                    statusSpan.textContent = 'En cours';
                }
                
                // Display image if available
                const imageElement = document.getElementById('view_image');
                const noImageElement = document.getElementById('view_no_image');
                
                if (data.offre.image) {
                    imageElement.src = 'uploads/offres/' + data.offre.image;
                    imageElement.classList.remove('hidden');
                    noImageElement.classList.add('hidden');
                } else {
                    imageElement.classList.add('hidden');
                    noImageElement.classList.remove('hidden');
                }
                
                // Display articles
                let articlesHtml = '';
                if (data.articles && data.articles.length > 0) {
                    articlesHtml = '<ul class="list-disc pl-5 space-y-1">';
                    data.articles.forEach(article => {
                        let remiseSpecifique = '';
                        if (article.remise_specifique) {
                            remiseSpecifique = ` (remise spécifique: ${article.remise_specifique}${data.offre.type_remise === 'pourcentage' ? ' %' : ' DH'})`;
                        }
                        articlesHtml += `<li class="text-sm">${article.reference} - ${article.designation}${remiseSpecifique}</li>`;
                    });
                    articlesHtml += '</ul>';
                } else {
                    articlesHtml = '<p class="text-sm text-gray-500">Tous les articles de la catégorie sont concernés</p>';
                }
                document.getElementById('view_articles').innerHTML = articlesHtml;
                
                // Set up buttons
                document.getElementById('edit_offre_button').onclick = function() {
                    closeModal('viewOffreModal');
                    editOffre(data.offre.id);
                };
                
                document.getElementById('duplicate_offre_button').onclick = function() {
                    closeModal('viewOffreModal');
                    duplicateOffre(data.offre.id);
                };
                
                // Display the modal
                openModal('viewOffreModal');
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Erreur lors de la récupération des données de l\'offre: ' + error.message);
            });
    }

    // Function to edit offre
    function editOffre(id) {
        // Fetch offre details from the server
        fetch(`get_offre.php?id=${id}`)
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
                
                // Fill the form with retrieved data
                document.getElementById('edit_id').value = data.offre.id;
                document.getElementById('edit_code').value = data.offre.code || '';
                document.getElementById('edit_nom').value = data.offre.nom || '';
                document.getElementById('edit_description').value = data.offre.description || '';
                document.getElementById('edit_categorie_id').value = data.offre.categorie_id || '';
                document.getElementById('edit_priorite').value = data.offre.priorite || '0';
                document.getElementById('edit_date_debut').value = data.offre.date_debut || '';
                document.getElementById('edit_date_fin').value = data.offre.date_fin || '';
                document.getElementById('edit_type_remise').value = data.offre.type_remise || 'pourcentage';
                document.getElementById('edit_valeur_remise').value = data.offre.valeur_remise || '';
                document.getElementById('edit_actif').value = data.offre.actif;
                document.getElementById('edit_conditions').value = data.offre.conditions || '';
                
                // Handle image display
                const currentImageElement = document.getElementById('edit_current_image');
                const noCurrentImageElement = document.getElementById('edit_no_current_image');
                
                if (data.offre.image) {
                    currentImageElement.src = 'uploads/offres/' + data.offre.image;
                    currentImageElement.classList.remove('hidden');
                    noCurrentImageElement.classList.add('hidden');
                } else {
                    currentImageElement.classList.add('hidden');
                    noCurrentImageElement.classList.remove('hidden');
                }
                
                // Reset delete image checkbox
                document.getElementById('edit_delete_image').checked = false;
                
                // Store selected articles for later use
                window.selectedArticles = data.articles ? data.articles.map(article => article.id) : [];
                
                // Load articles for the selected category
                loadArticles(data.offre.categorie_id, 'edit_articles_container', window.selectedArticles);
                
                // Display the modal
                openModal('editOffreModal');
            })
            .catch(error => {
                console.error('Erreur:', error);
                alert('Erreur lors de la récupération des données de l\'offre: ' + error.message);
            });
    }

    // Function to duplicate offre
    function duplicateOffre(id) {
        if (confirm('Voulez-vous dupliquer cette offre ?')) {
            // Redirect to the duplicate page
            window.location.href = `duplicate_offre.php?id=${id}`;
        }
    }

    // Function to delete offre
    function deleteOffre(id) {
        document.getElementById('delete_id').value = id;
        openModal('deleteOffreModal');
    }

    // Format date function
    function formatDate(dateString) {
        if (!dateString) return '-';
        const date = new Date(dateString);
        return date.toLocaleDateString('fr-FR');
    }

    // Load articles on page load for add form
    document.addEventListener('DOMContentLoaded', function() {
        loadArticles('', 'articles_container');
        
        // Set default date for new offers
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('date_debut').value = today;
    });

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

    // Form validation for add form
    document.getElementById('addOffreForm').addEventListener('submit', function(event) {
        const codeInput = document.getElementById('code');
        const nomInput = document.getElementById('nom');
        const dateDebutInput = document.getElementById('date_debut');
        const valeurRemiseInput = document.getElementById('valeur_remise');
        
        let isValid = true;
        
        // Validation du code (alphanumeric, max 20 chars)
        if (!/^[a-zA-Z0-9-_]{1,20}$/.test(codeInput.value)) {
            codeInput.classList.add('border-red-500');
            isValid = false;
            alert('Le code doit contenir uniquement des lettres, chiffres, tirets ou underscores (max 20 caractères)');
        } else {
            codeInput.classList.remove('border-red-500');
        }
        
        // Validation du nom (non vide, max 100 chars)
        if (nomInput.value.trim() === '' || nomInput.value.length > 100) {
            nomInput.classList.add('border-red-500');
            isValid = false;
            alert('Le nom est requis et ne doit pas dépasser 100 caractères');
        } else {
            nomInput.classList.remove('border-red-500');
        }
        
        // Validation de la date de début
        if (!dateDebutInput.value) {
            dateDebutInput.classList.add('border-red-500');
            isValid = false;
            alert('La date de début est requise');
        } else {
            dateDebutInput.classList.remove('border-red-500');
        }
        
        // Validation de la valeur de remise
        if (valeurRemiseInput.value <= 0) {
            valeurRemiseInput.classList.add('border-red-500');
            isValid = false;
            alert('La valeur de la remise doit être supérieure à 0');
        } else {
            valeurRemiseInput.classList.remove('border-red-500');
        }
        
        if (!isValid) {
            event.preventDefault();
        }
    });

    // Form validation for edit form
    document.getElementById('editOffreForm').addEventListener('submit', function(event) {
        const codeInput = document.getElementById('edit_code');
        const nomInput = document.getElementById('edit_nom');
        const dateDebutInput = document.getElementById('edit_date_debut');
        const valeurRemiseInput = document.getElementById('edit_valeur_remise');
        
        let isValid = true;
        
        // Validation du code (alphanumeric, max 20 chars)
        if (!/^[a-zA-Z0-9-_]{1,20}$/.test(codeInput.value)) {
            codeInput.classList.add('border-red-500');
            isValid = false;
            alert('Le code doit contenir uniquement des lettres, chiffres, tirets ou underscores (max 20 caractères)');
        } else {
            codeInput.classList.remove('border-red-500');
        }
        
        // Validation du nom (non vide, max 100 chars)
        if (nomInput.value.trim() === '' || nomInput.value.length > 100) {
            nomInput.classList.add('border-red-500');
            isValid = false;
            alert('Le nom est requis et ne doit pas dépasser 100 caractères');
        } else {
            nomInput.classList.remove('border-red-500');
        }
        
        // Validation de la date de début
        if (!dateDebutInput.value) {
            dateDebutInput.classList.add('border-red-500');
            isValid = false;
            alert('La date de début est requise');
        } else {
            dateDebutInput.classList.remove('border-red-500');
        }
        
        // Validation de la valeur de remise
        if (valeurRemiseInput.value <= 0) {
            valeurRemiseInput.classList.add('border-red-500');
            isValid = false;
            alert('La valeur de la remise doit être supérieure à 0');
        } else {
            valeurRemiseInput.classList.remove('border-red-500');
        }
        
        if (!isValid) {
            event.preventDefault();
        }
    });
</script>

<?php include '../includes/footer.php'; ?>


