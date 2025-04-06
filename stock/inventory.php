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
    // Pour le développement, créer un utilisateur factice
    $_SESSION['user_id'] = 1;
}

// Utilisateur temporaire pour éviter l'erreur
$currentUser = [
    'name' => 'Utilisateur Test',
    'role' => 'Administrateur'
];

// Connexion à la base de données
$database = new Database();
$db = $database->getConnection();

// Configuration de la pagination
$mouvements_per_page = 20;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $mouvements_per_page;

// Construction de la requête avec filtres
$where_conditions = [];
$params = [];

// Filtres
if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $where_conditions[] = "(a.reference LIKE :search OR a.designation LIKE :search OR h.Commentaire LIKE :search OR h.Utilisateur LIKE :search)";
    $params[':search'] = $search;
}

if (isset($_GET['type_operation']) && !empty($_GET['type_operation'])) {
    $where_conditions[] = "h.Type_Operation = :type_operation";
    $params[':type_operation'] = $_GET['type_operation'];
}

if (isset($_GET['date_debut']) && !empty($_GET['date_debut'])) {
    $where_conditions[] = "DATE(h.Date_Operation) >= :date_debut";
    $params[':date_debut'] = $_GET['date_debut'];
}

if (isset($_GET['date_fin']) && !empty($_GET['date_fin'])) {
    $where_conditions[] = "DATE(h.Date_Operation) <= :date_fin";
    $params[':date_fin'] = $_GET['date_fin'];
}

if (isset($_GET['article_id']) && !empty($_GET['article_id'])) {
    $where_conditions[] = "h.ID_Article = :article_id";
    $params[':article_id'] = $_GET['article_id'];
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

// Récupérer le nombre total de mouvements
$count_query = "SELECT COUNT(*) as total 
                FROM historique_articles h 
                LEFT JOIN articles a ON h.ID_Article = a.id 
                LEFT JOIN commandes c ON h.ID_Commande = c.ID_Commande
                $where_clause";
$count_stmt = $db->prepare($count_query);
foreach ($params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
$total_mouvements = $total_result['total'];
$total_pages = ceil($total_mouvements / $mouvements_per_page);

// Récupérer les mouvements de stock pour la page courante - SANS la jointure problématique
$query = "SELECT h.ID_Historique, h.ID_Article, h.ID_Commande, h.Type_Operation, 
          h.Date_Operation, h.Quantite, h.Prix_Unitaire, h.Utilisateur, h.Commentaire,
          a.reference, a.designation, c.Numero_Commande, f.Code_Fournisseur, h.ID_Fournisseur
          FROM historique_articles h
          LEFT JOIN articles a ON h.ID_Article = a.id
          LEFT JOIN commandes c ON h.ID_Commande = c.ID_Commande
          LEFT JOIN fournisseurs f ON h.ID_Fournisseur = f.ID_Fournisseur
          $where_clause
          ORDER BY h.Date_Operation DESC
          LIMIT :limit OFFSET :offset";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindParam(':limit', $mouvements_per_page, PDO::PARAM_INT);
$stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$mouvements = $stmt->fetchAll(PDO::FETCH_ASSOC);
// Récupérer les informations utilisateur pour chaque mouvement sans cache
foreach ($mouvements as &$mouvement) {
    $user_id = $mouvement['Utilisateur']; // Utilisateur contient l'ID
    if (!empty($user_id)) {
        $user_query = "SELECT nom, prenom FROM users WHERE id = :user_id";
        $user_stmt = $db->prepare($user_query);
        $user_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $user_stmt->execute();
        $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user_data) {
            $mouvement['nom'] = $user_data['nom'];
            $mouvement['prenom'] = $user_data['prenom'];
        } else {
            $mouvement['nom'] = '';
            $mouvement['prenom'] = '';
        }
    } else {
        $mouvement['nom'] = '';
        $mouvement['prenom'] = '';
    }
}

// Récupérer la liste des articles pour le filtre
$articles_query = "SELECT id, reference, designation FROM articles ORDER BY reference";
$articles_stmt = $db->prepare($articles_query);
$articles_stmt->execute();
$articles = $articles_stmt->fetchAll(PDO::FETCH_ASSOC);

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
                <h1 class="text-2xl font-semibold text-gray-800">Historique des mouvements de stock</h1>
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <span class="text-gray-700"><?php echo isset($currentUser['name']) ? htmlspecialchars($currentUser['name']) : 'Utilisateur'; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Content -->
        <div class="container mx-auto px-6 py-8">
            <!-- Bouton de retour -->
            <div class="mb-6">
                <a href="index.php" class="inline-flex items-center px-4 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                    Retour à l'accueil
                </a>
            </div>

            <!-- Filtres -->
            <div class="mb-6 p-4 bg-white rounded-lg shadow">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-6 gap-4">
                    <div>
                        <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Recherche</label>
                        <input type="text" name="search" id="search" value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>" placeholder="Référence, désignation, commentaire..." 
                               class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label for="type_operation" class="block text-sm font-medium text-gray-700 mb-1">Type d'opération</label>
                        <select name="type_operation" id="type_operation" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Tous les types</option>
                            <option value="Commande" <?php echo (isset($_GET['type_operation']) && $_GET['type_operation'] == 'Commande') ? 'selected' : ''; ?>>Commande</option>
                            <option value="Réception" <?php echo (isset($_GET['type_operation']) && $_GET['type_operation'] == 'Réception') ? 'selected' : ''; ?>>Réception</option>
                            <option value="Ajustment" <?php echo (isset($_GET['type_operation']) && $_GET['type_operation'] == 'Ajustment') ? 'selected' : ''; ?>>Ajustement</option>
                            <option value="Annulation" <?php echo (isset($_GET['type_operation']) && $_GET['type_operation'] == 'Annulation') ? 'selected' : ''; ?>>Annulation</option>
                            <option value="Retour" <?php echo (isset($_GET['type_operation']) && $_GET['type_operation'] == 'Retour') ? 'selected' : ''; ?>>Retour</option>
                        </select>
                    </div>
                    <div>
                        <label for="date_debut" class="block text-sm font-medium text-gray-700 mb-1">Date début</label>
                        <input type="date" name="date_debut" id="date_debut" value="<?php echo isset($_GET['date_debut']) ? htmlspecialchars($_GET['date_debut']) : ''; ?>" 
                               class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label for="date_fin" class="block text-sm font-medium text-gray-700 mb-1">Date fin</label>
                        <input type="date" name="date_fin" id="date_fin" value="<?php echo isset($_GET['date_fin']) ? htmlspecialchars($_GET['date_fin']) : ''; ?>" 
                               class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label for="article_id" class="block text-sm font-medium text-gray-700 mb-1">Article</label>
                        <select name="article_id" id="article_id" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Tous les articles</option>
                            <?php foreach ($articles as $article): ?>
                                <option value="<?php echo $article['id']; ?>" <?php echo (isset($_GET['article_id']) && $_GET['article_id'] == $article['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($article['reference'] . ' - ' . $article['designation']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg">
                            Filtrer
                        </button>
                    </div>
                </form>
            </div>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                <!-- Total Mouvements -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-indigo-100 text-indigo-600">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"></path>
                            </svg>
                        </div>
                        <div class="ml-4">
                            <h2 class="text-gray-600 text-sm font-medium">Total Mouvements</h2>
                            <p class="text-2xl font-semibold text-gray-800">
                                <?php
                                    $query = "SELECT COUNT(*) as total FROM historique_articles";
                                    $stmt = $db->prepare($query);
                                    $stmt->execute();
                                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                    echo $result['total'];
                                ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Commandes -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"></path>
                            </svg>
                        </div>
                        <div class="ml-4">
                            <h2 class="text-gray-600 text-sm font-medium">Commandes</h2>
                            <p class="text-2xl font-semibold text-gray-800">
                                <?php
                                    $query = "SELECT COUNT(*) as total FROM historique_articles WHERE Type_Operation = 'Commande'";
                                    $stmt = $db->prepare($query);
                                    $stmt->execute();
                                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                    echo $result['total'];
                                ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Réceptions -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-100 text-green-600">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"></path>
                            </svg>
                        </div>
                        <div class="ml-4">
                            <h2 class="text-gray-600 text-sm font-medium">Réceptions</h2>
                            <p class="text-2xl font-semibold text-gray-800">
                                <?php
                                    $query = "SELECT COUNT(*) as total FROM historique_articles WHERE Type_Operation = 'Réception'";
                                    $stmt = $db->prepare($query);
                                    $stmt->execute();
                                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                    echo $result['total'];
                                ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Ajustements -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-yellow-100 text-yellow-600">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path>
                            </svg>
                        </div>
                        <div class="ml-4">
                            <h2 class="text-gray-600 text-sm font-medium">Ajustements</h2>
                            <p class="text-2xl font-semibold text-gray-800">
                                <?php
                                    $query = "SELECT COUNT(*) as total FROM historique_articles WHERE Type_Operation = 'Modification'";
                                    $stmt = $db->prepare($query);
                                    $stmt->execute();
                                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                    echo $result['total'];
                                ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Export Buttons -->
            <div class="mb-6 flex justify-end">
                <a href="export_inventory.php?format=csv<?php echo isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?><?php echo isset($_GET['type_operation']) ? '&type_operation=' . urlencode($_GET['type_operation']) : ''; ?><?php echo isset($_GET['date_debut']) ? '&date_debut=' . urlencode($_GET['date_debut']) : ''; ?><?php echo isset($_GET['date_fin']) ? '&date_fin=' . urlencode($_GET['date_fin']) : ''; ?><?php echo isset($_GET['article_id']) ? '&article_id=' . urlencode($_GET['article_id']) : ''; ?>" 
                   class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition flex items-center mr-2">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                    </svg>
                    Exporter CSV
                </a>
                <a href="export_inventory.php?format=pdf<?php echo isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?><?php echo isset($_GET['type_operation']) ? '&type_operation=' . urlencode($_GET['type_operation']) : ''; ?><?php echo isset($_GET['date_debut']) ? '&date_debut=' . urlencode($_GET['date_debut']) : ''; ?><?php echo isset($_GET['date_fin']) ? '&date_fin=' . urlencode($_GET['date_fin']) : ''; ?><?php echo isset($_GET['article_id']) ? '&article_id=' . urlencode($_GET['article_id']) : ''; ?>" 
                   class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition flex items-center">
                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                    </svg>
                    Exporter PDF
                </a>
            </div>

            <!-- Inventory Table -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Date
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Article
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Type
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Quantité
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Prix Unitaire
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Commande/Fournisseur
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Utilisateur
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Commentaire
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($mouvements)): ?>
                                <tr>
                                    <td colspan="8" class="px-6 py-4 text-center text-gray-500">
                                        Aucun mouvement trouvé
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($mouvements as $mouvement): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">
                                                <?php echo date('d/m/Y H:i', strtotime($mouvement['Date_Operation'])); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($mouvement['reference']); ?>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                <?php echo htmlspecialchars($mouvement['designation']); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php 
                                                $type_class = 'bg-gray-100 text-gray-800';
                                                switch ($mouvement['Type_Operation']) {
                                                    case 'Commande':
                                                        $type_class = 'bg-blue-100 text-blue-800';
                                                        break;
                                                    case 'Réception':
                                                        $type_class = 'bg-green-100 text-green-800';
                                                        break;
                                                    case 'Ajustment':
                                                        $type_class = 'bg-yellow-100 text-yellow-800';
                                                        break;
                                                    case 'Annulation':
                                                        $type_class = 'bg-red-100 text-red-800';
                                                        break;
                                                    case 'Retour':
                                                        $type_class = 'bg-purple-100 text-purple-800';
                                                        break;
                                                }
                                            ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $type_class; ?>">
                                                <?php echo htmlspecialchars($mouvement['Type_Operation']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900 font-medium">
                                                <?php echo intval($mouvement['Quantite']); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo number_format($mouvement['Prix_Unitaire'], 2, ',', ' '); ?> DH
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php if ($mouvement['Type_Operation'] == 'Réception' && !empty($mouvement['ID_Fournisseur'])): ?>
                                                <a href="../fournisseurs/view.php?id=<?php echo $mouvement['ID_Fournisseur']; ?>" class="text-green-600 hover:text-green-900">
                                                    <?php echo htmlspecialchars($mouvement['Code_Fournisseur'] ??  $mouvement['Code_Fournisseur']); ?>
                                                  
                                                </a>
                                            <?php elseif (($mouvement['Type_Operation'] == 'Commande' || $mouvement['Type_Operation'] == 'Annulation') && !empty($mouvement['Numero_Commande'])): ?>
                                                <a href="../orders/view.php?id=<?php echo $mouvement['ID_Commande']; ?>" class="text-indigo-600 hover:text-indigo-900">
                                                    <?php echo htmlspecialchars($mouvement['Numero_Commande']); ?>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-gray-500">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php if (!empty($mouvement['nom']) && !empty($mouvement['prenom'])): ?>
                                                <?php echo htmlspecialchars($mouvement['prenom'] . ' ' . $mouvement['nom']); ?>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($mouvement['Utilisateur']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm text-gray-900">
                                                <?php echo htmlspecialchars($mouvement['Commentaire']); ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Pagination -->
            <div class="mt-6 flex justify-between items-center">
                <div class="text-sm text-gray-500">
                    Affichage de <span class="font-medium">
                        <?php 
                            $start_count = $total_mouvements > 0 ? $offset + 1 : 0;
                            $end_count = min($total_mouvements, $offset + count($mouvements));
                            echo $start_count . '-' . $end_count;
                        ?>
                    </span> 
                    mouvement(s) sur <span class="font-medium"><?php echo $total_mouvements; ?></span> au total
                </div>
                
                <?php if ($total_pages > 1): ?>
                    <div class="flex space-x-2">
                        <!-- Bouton Précédent -->
                        <?php if ($current_page > 1): ?>
                            <a href="?page=<?php echo $current_page - 1; ?><?php echo isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?><?php echo isset($_GET['type_operation']) ? '&type_operation=' . urlencode($_GET['type_operation']) : ''; ?><?php echo isset($_GET['date_debut']) ? '&date_debut=' . urlencode($_GET['date_debut']) : ''; ?><?php echo isset($_GET['date_fin']) ? '&date_fin=' . urlencode($_GET['date_fin']) : ''; ?><?php echo isset($_GET['article_id']) ? '&article_id=' . urlencode($_GET['article_id']) : ''; ?>" 
                            class="px-3 py-1 rounded border border-gray-300 text-gray-700 hover:bg-gray-50">
                                Précédent
                            </a>
                        <?php else: ?>
                            <button class="px-3 py-1 rounded border border-gray-300 text-gray-400 cursor-not-allowed opacity-50" disabled>
                                Précédent
                            </button>
                        <?php endif; ?>

                        <!-- Pages numérotées -->
                        <?php
                            // Afficher les numéros de page
                            $max_visible_pages = 3; // Nombre maximum de boutons de page à afficher
                            
                            // Calculer les pages à afficher
                            if ($total_pages <= $max_visible_pages) {
                                $start_page = 1;
                                $end_page = $total_pages;
                            } else {
                                $start_page = max(1, min($current_page - 1, $total_pages - $max_visible_pages + 1));
                                $end_page = min($start_page + $max_visible_pages - 1, $total_pages);
                            }
                            
                            for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <?php if ($i == $current_page): ?>
                                <button class="px-3 py-1 rounded bg-indigo-600 text-white">
                                    <?php echo $i; ?>
                                </button>
                            <?php else: ?>
                                <a href="?page=<?php echo $i; ?><?php echo isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?><?php echo isset($_GET['type_operation']) ? '&type_operation=' . urlencode($_GET['type_operation']) : ''; ?><?php echo isset($_GET['date_debut']) ? '&date_debut=' . urlencode($_GET['date_debut']) : ''; ?><?php echo isset($_GET['date_fin']) ? '&date_fin=' . urlencode($_GET['date_fin']) : ''; ?><?php echo isset($_GET['article_id']) ? '&article_id=' . urlencode($_GET['article_id']) : ''; ?>" 
                                   class="px-3 py-1 rounded border border-gray-300 text-gray-700 hover:bg-gray-50">
                                    <?php echo $i; ?>
                                </a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <!-- Bouton Suivant -->
                        <?php if ($current_page < $total_pages): ?>
                            <a href="?page=<?php echo $current_page + 1; ?><?php echo isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?><?php echo isset($_GET['type_operation']) ? '&type_operation=' . urlencode($_GET['type_operation']) : ''; ?><?php echo isset($_GET['date_debut']) ? '&date_debut=' . urlencode($_GET['date_debut']) : ''; ?><?php echo isset($_GET['date_fin']) ? '&date_fin=' . urlencode($_GET['date_fin']) : ''; ?><?php echo isset($_GET['article_id']) ? '&article_id=' . urlencode($_GET['article_id']) : ''; ?>" 
                               class="px-3 py-1 rounded border border-gray-300 text-gray-700 hover:bg-gray-50">
                                Suivant
                            </a>
                        <?php else: ?>
                            <button class="px-3 py-1 rounded border border-gray-300 text-gray-400 cursor-not-allowed opacity-50" disabled>
                                Suivant
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include $root_path . '/includes/footer.php'; ?>
