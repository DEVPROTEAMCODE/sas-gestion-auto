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

// Inclure l'en-tête
include $root_path . '/includes/header.php';

// Récupérer la liste des fournisseurs
$database = new Database();
$db = $database->getConnection();
$query = "SELECT ID_Fournisseur, Code_Fournisseur FROM fournisseurs ORDER BY Code_Fournisseur";
$stmt = $db->prepare($query);
$stmt->execute();
$fournisseurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<?php
// Assurez-vous que session_start() est appelé au début du fichier

// Afficher les messages de succès
if (isset($_SESSION['success'])) {
    echo '<div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline">' . htmlspecialchars($_SESSION['success']) . '</span>
          </div>';
    // Supprimer le message après l'avoir affiché pour qu'il ne s'affiche qu'une fois
    unset($_SESSION['success']);
}

// Afficher les messages d'erreur (si vous en avez)
if (isset($_SESSION['error'])) {
    echo '<div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline">' . htmlspecialchars($_SESSION['error']) . '</span>
          </div>';
    unset($_SESSION['error']);
}
?>

<div class="flex h-screen bg-gray-100">
    <!-- Sidebar -->
    <?php include $root_path . '/includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 overflow-y-auto">
        <!-- Top header -->
        <div class="bg-white shadow-sm">
            <div class="container mx-auto px-6 py-4 flex justify-between items-center">
                <h1 class="text-2xl font-semibold text-gray-800">Gestion de Stock</h1>
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <span class="text-gray-700"><?php echo isset($currentUser['name']) ? htmlspecialchars($currentUser['name']) : 'Utilisateur'; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stock Content -->
        <div class="container mx-auto px-6 py-8">
            <!-- Search and Add Button -->
            <div class="flex flex-col md:flex-row md:justify-between md:items-center mb-6">
                <div class="flex-1">
                    <form action="" method="GET" class="mb-4 md:mb-0">
                        <div class="relative">
                            <input type="text" name="search" value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>" placeholder="Rechercher un article..." class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                            <button type="submit" class="absolute right-0 top-0 h-full px-4 text-gray-600 hover:text-indigo-500">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                            </button>
                        </div>
                    </form>
                </div>
                <div class="flex space-x-3">
                    <a href="create.php" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Ajouter un article
                    </a>
                    <a href="inventory.php" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                        </svg>
                        Inventaire
                    </a>
                </div>
            </div>

            <!-- Statistics Cards -->
            <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
                <!-- Total Articles -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-indigo-100 text-indigo-600">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                            </svg>
                        </div>
                        <div class="ml-4">
                            <h2 class="text-gray-600 text-sm font-medium">Total Articles</h2>
                            <p class="text-2xl font-semibold text-gray-800">
                                <?php
                                    $query = "SELECT COUNT(*) as total FROM articles";
                                    $stmt = $db->prepare($query);
                                    $stmt->execute();
                                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                    echo $result['total'];
                                ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Articles en Stock Faible -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-red-100 text-red-600">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                            </svg>
                        </div>
                        <div class="ml-4">
                            <h2 class="text-gray-600 text-sm font-medium">Stock Faible</h2>
                            <p class="text-2xl font-semibold text-gray-800">
                                <?php
                                    $query = "SELECT COUNT(*) as total FROM articles WHERE quantite_stock <= seuil_alerte";
                                    $stmt = $db->prepare($query);
                                    $stmt->execute();
                                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                    echo $result['total'];
                                ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Valeur du Stock -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-green-100 text-green-600">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <div class="ml-4">
                            <h2 class="text-gray-600 text-sm font-medium">Valeur Stock</h2>
                            <p class="text-2xl font-semibold text-gray-800">
                                <?php
                                    $query = "SELECT SUM(prix_achat * quantite_stock) as total FROM articles";
                                    $stmt = $db->prepare($query);
                                    $stmt->execute();
                                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                                    echo number_format($result['total'] ?? 0, 2, ',', ' ') . ' DH';
                                ?>
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Derniers Articles Ajoutés -->
                <div class="bg-white rounded-lg shadow p-6">
                    <div class="flex items-center">
                        <div class="p-3 rounded-full bg-blue-100 text-blue-600">
                            <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                            </svg>
                        </div>
                        <div class="ml-4">
                            <h2 class="text-gray-600 text-sm font-medium">Ajoutés (30j)</h2>
                            <p class="text-2xl font-semibold text-gray-800">
                                <?php
                                    $query = "SELECT COUNT(*) as total FROM articles WHERE date_creation >= DATE_SUB(CURRENT_DATE, INTERVAL 30 DAY)";
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

            <!-- Filtres avancés -->
            <div class="mb-6 p-4 bg-white rounded-lg shadow">
                <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Recherche</label>
                        <input type="text" name="search" id="search" value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>" placeholder="Référence, désignation..." 
                               class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    </div>
                    <div>
                        <label for="categorie" class="block text-sm font-medium text-gray-700 mb-1">Catégorie</label>
                        <select name="categorie" id="categorie" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Toutes les catégories</option>
                            <?php
                                $query = "SELECT id, nom as nom_categorie FROM categorie ORDER BY nom";
                                $stmt = $db->prepare($query);
                                $stmt->execute();
                                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                    $selected = (isset($_GET['categorie']) && $_GET['categorie'] == $row['id']) ? 'selected' : '';
                                    echo '<option value="' . htmlspecialchars($row['id']) . '" ' . $selected . '>' . htmlspecialchars($row['nom_categorie']) . '</option>';
                                }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label for="stock" class="block text-sm font-medium text-gray-700 mb-1">Niveau de stock</label>
                        <select name="stock" id="stock" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="">Tous les niveaux</option>
                            <option value="low" <?php echo (isset($_GET['stock']) && $_GET['stock'] == 'low') ? 'selected' : ''; ?>>Stock faible</option>
                            <option value="normal" <?php echo (isset($_GET['stock']) && $_GET['stock'] == 'normal') ? 'selected' : ''; ?>>Stock normal</option>
                            <option value="high" <?php echo (isset($_GET['stock']) && $_GET['stock'] == 'high') ? 'selected' : ''; ?>>Stock élevé</option>
                        </select>
                    </div>
                    <div class="flex items-end">
                        <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-medium py-2 px-4 rounded-lg">
                            Filtrer
                        </button>
                    </div>
                </form>
            </div>

            <!-- Stock Table -->
            <?php
                // Configuration de la pagination
                $articles_per_page = 10;
                $current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
                $offset = ($current_page - 1) * $articles_per_page;
                
                // Construction de la requête avec filtres
                $where_conditions = [];
                $params = [];
                
                if (isset($_GET['search']) && !empty($_GET['search'])) {
                    $search = '%' . $_GET['search'] . '%';
                    $where_conditions[] = "(a.reference LIKE :search OR a.designation LIKE :search)";
                    $params[':search'] = $search;
                }
                
                if (isset($_GET['categorie']) && !empty($_GET['categorie'])) {
                    $where_conditions[] = "a.categorie_id = :categorie";
                    $params[':categorie'] = $_GET['categorie'];
                }
                
                if (isset($_GET['stock']) && !empty($_GET['stock'])) {
                    switch($_GET['stock']) {
                        case 'low':
                            $where_conditions[] = "a.quantite_stock <= a.seuil_alerte";
                            break;
                        case 'normal':
                            $where_conditions[] = "a.quantite_stock > a.seuil_alerte AND a.quantite_stock <= a.seuil_alerte * 3";
                            break;
                        case 'high':
                            $where_conditions[] = "a.quantite_stock > a.seuil_alerte * 3";
                            break;
                    }
                }
                
                $where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";
                
                // Récupérer le nombre total d'articles
                $count_query = "SELECT COUNT(*) as total FROM articles a LEFT JOIN categorie c ON a.categorie_id = c.id $where_clause";
                $count_stmt = $db->prepare($count_query);
                foreach ($params as $key => $value) {
                    $count_stmt->bindValue($key, $value);
                }
                $count_stmt->execute();
                $total_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
                $total_articles = $total_result['total'];
                $total_pages = ceil($total_articles / $articles_per_page);
                
                // Récupérer les articles pour la page courante
                $query = "SELECT a.id, a.reference, a.designation, a.categorie_id, c.nom as nom_cat, 
                          a.quantite_stock, a.seuil_alerte, a.prix_achat, a.prix_vente_ht, 
                          a.emplacement, a.date_creation, a.derniere_mise_a_jour 
                          FROM articles a 
                          LEFT JOIN categorie c ON a.categorie_id = c.id 
                          $where_clause
                          ORDER BY a.reference DESC
                          LIMIT :limit OFFSET :offset";
                $stmt = $db->prepare($query);
                foreach ($params as $key => $value) {
                    $stmt->bindValue($key, $value);
                }
                $stmt->bindParam(':limit', $articles_per_page, PDO::PARAM_INT);
                $stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
                $articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            ?>

            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Référence
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Désignation
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Catégorie
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Stock
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Prix Achat
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Prix Vente
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Emplacement
                                </th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php if (empty($articles)): ?>
                                <tr>
                                    <td colspan="8" class="px-6 py-4 text-center text-gray-500">
                                        Aucun article trouvé
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($articles as $article): ?>
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($article['reference']); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <div class="text-sm text-gray-900">
                                                <?php echo htmlspecialchars($article['designation']); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <div class="text-sm text-gray-900">
                                                <?php echo htmlspecialchars($article['nom_cat'] ?? 'Non catégorisé'); ?>
                                            </div>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <?php 
                                                $stock_class = 'bg-green-100 text-green-800';
                                                if ($article['quantite_stock'] <= $article['seuil_alerte']) {
                                                    $stock_class = 'bg-red-100 text-red-800';
                                                } elseif ($article['quantite_stock'] <= $article['seuil_alerte'] * 2) {
                                                    $stock_class = 'bg-yellow-100 text-yellow-800';
                                                }
                                            ?>
                                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $stock_class; ?>">
                                                <?php echo htmlspecialchars($article['quantite_stock']); ?>
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo number_format($article['prix_achat'], 2, ',', ' '); ?> DH
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo number_format($article['prix_vente_ht'], 2, ',', ' '); ?> DH
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo htmlspecialchars($article['emplacement']); ?>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                            <div class="flex justify-end space-x-2">
                                                <a href="view_article.php?id=<?php echo $article['id']; ?>" class="text-indigo-600 hover:text-indigo-900" title="Voir">
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                    </svg>
                                                </a>
                                                <a href="edit.php?id=<?php echo $article['id']; ?>" class="text-blue-600 hover:text-blue-900" title="Modifier">
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                    </svg>
                                                </a>
                                                <a href="javascript:void(0)" onclick="openStockModal(<?php echo $article['id']; ?>, '<?php echo htmlspecialchars($article['reference']); ?>', '<?php echo htmlspecialchars($article['designation']); ?>', <?php echo $article['quantite_stock']; ?>)" class="text-green-600 hover:text-green-900" title="Ajuster le stock">
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"></path>
                                                    </svg>
                                                </a>
                                                <a href="javascript:void(0)" onclick="Delete(<?php echo $article['id']; ?>)" class="text-red-600 hover:text-red-900" title="Supprimer">
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                                    </svg>
                                                </a>
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
                            $start_count = $total_articles > 0 ? $offset + 1 : 0;
                            $end_count = min($total_articles, $offset + count($articles));
                            echo $start_count . '-' . $end_count;
                        ?>
                    </span> 
                    article(s) sur <span class="font-medium"><?php echo $total_articles; ?></span> au total
                </div>
                
                <?php if ($total_pages > 1): ?>
                    <div class="flex space-x-2">
                        <!-- Bouton Précédent -->
                        <?php if ($current_page > 1): ?>
                            <a href="?page=<?php echo $current_page - 1; ?><?php echo isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?><?php echo isset($_GET['categorie']) ? '&categorie=' . urlencode($_GET['categorie']) : ''; ?><?php echo isset($_GET['stock']) ? '&stock=' . urlencode($_GET['stock']) : ''; ?>" 
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
                                        <a href="?page=<?php echo $i; ?><?php echo isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?><?php echo isset($_GET['categorie']) ? '&categorie=' . urlencode($_GET['categorie']) : ''; ?><?php echo isset($_GET['stock']) ? '&stock=' . urlencode($_GET['stock']) : ''; ?>" 
                                           class="px-3 py-1 rounded border border-gray-300 text-gray-700 hover:bg-gray-50">
                                            <?php echo $i; ?>
                                        </a>
                                    <?php endif; ?>
                                <?php endfor; ?>
        
                                <!-- Bouton Suivant -->
                                <?php if ($current_page < $total_pages): ?>
                                    <a href="?page=<?php echo $current_page + 1; ?><?php echo isset($_GET['search']) ? '&search=' . urlencode($_GET['search']) : ''; ?><?php echo isset($_GET['categorie']) ? '&categorie=' . urlencode($_GET['categorie']) : ''; ?><?php echo isset($_GET['stock']) ? '&stock=' . urlencode($_GET['stock']) : ''; ?>" 
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
        
                    <!-- Delete Confirmation Modal -->
                    <div id="deleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
                        <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                            <div class="px-6 py-4 border-b">
                                <h3 class="text-lg font-semibold text-gray-900">Confirmation de suppression</h3>
                            </div>
                            <div class="px-6 py-4">
                                <p class="text-gray-700">Êtes-vous sûr de vouloir supprimer cet article ? Cette action est irréversible.</p>
                            </div>
                            <div class="px-6 py-4 bg-gray-50 flex justify-end space-x-3 rounded-b-lg">
                                <button type="button" onclick="cancelDelete()" class="px-4 py-2 bg-gray-200 text-gray-800 rounded hover:bg-gray-300 transition">
                                    Annuler
                                </button>
                                <a href="#" id="confirmDeleteBtn" class="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700 transition">
                                    Supprimer
                                </a>
                            </div>
                        </div>
                    </div>
        
                    <!-- Stock Adjustment Modal -->
                    <div id="stockModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
                        <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
                            <div class="px-6 py-4 border-b">
                                <h3 class="text-lg font-semibold text-gray-900">Ajustement de stock</h3>
                            </div>
                            <form action="update_stock.php" method="POST">
                                <input type="hidden" id="article_id" name="article_id">
                                <input type="hidden" id="current_stock" name="current_stock">
                                <div class="px-6 py-4">
                                    <div class="mb-4">
                                        <p class="text-gray-700">Article: <span id="article_info" class="font-medium"></span></p>
                                        <p class="text-gray-700 mt-1">Stock actuel: <span id="stock_info" class="font-medium"></span></p>
                                    </div>
                                    <div class="mb-4">
                                        <label for="type_mouvement" class="block text-sm font-medium text-gray-700 mb-1">Type de mouvement</label>
                                        <select id="type_mouvement" name="type_mouvement" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" onchange="toggleMouvementOptions()">
                                            <option value="reception">Réception</option>
                                            <option value="modification">Modification</option>
                                            <option value="retour">Retour</option>
                                        </select>
                                    </div>
                                    
                                    <!-- Champ pour la réception (ajouter au stock) -->
                                    <div id="reception_fields">
                                        <div class="mb-4">
                                            <label for="quantite_reception" class="block text-sm font-medium text-gray-700 mb-1">Quantité à ajouter</label>
                                            <input type="number" id="quantite_reception" name="quantite_reception" min="1" value="1" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </div>
                                        <div class="mb-4">
                                            <label for="fournisseur_id" class="block text-sm font-medium text-gray-700 mb-1">Fournisseur</label>
                                            <select id="fournisseur_id" name="fournisseur_id" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                                <option value="">Sélectionner un fournisseur</option>
                                                <?php foreach ($fournisseurs as $fournisseur): ?>
                                                    <option value="<?php echo $fournisseur['ID_Fournisseur']; ?>"><?php echo htmlspecialchars($fournisseur['Code_Fournisseur']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <!-- Champ pour la modification directe du stock -->
                                    <div id="modification_fields" style="display: none;">
                                        <div class="mb-4">
                                            <label for="quantite_modification" class="block text-sm font-medium text-gray-700 mb-1">Nouvelle quantité en stock</label>
                                            <input type="number" id="quantite_modification" name="quantite_modification" min="0" value="0" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </div>
                                    </div>
                                    
                                    <!-- Champ pour le retour -->
                                    <div id="retour_fields" style="display: none;">
                                        <div class="mb-4">
                                            <label for="quantite_retour" class="block text-sm font-medium text-gray-700 mb-1">Quantité à retourner</label>
                                            <input type="number" id="quantite_retour" name="quantite_retour" min="1" value="1" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                        </div>
                                    </div>
                                    
                                    <div class="mb-4">
                                        <label for="commentaire" class="block text-sm font-medium text-gray-700 mb-1">Commentaire</label>
                                        <textarea id="commentaire" name="commentaire" rows="2" class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                                    </div>
                                </div>
                                <div class="px-6 py-4 bg-gray-50 flex justify-end space-x-3 rounded-b-lg">
                                    <button type="button" onclick="closeStockModal()" class="px-4 py-2 bg-gray-200 text-gray-800 rounded hover:bg-gray-300 transition">
                                        Annuler
                                    </button>
                                    <button type="submit" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 transition">
                                        Confirmer
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
        
                    <!-- JavaScript for modals -->
                    <script>
                        function Delete(id) {
                            const modal = document.getElementById('deleteModal');
                            const confirmBtn = document.getElementById('confirmDeleteBtn');
                            
                            modal.classList.remove('hidden');
                            confirmBtn.href = 'delete_article.php?id=' + id;
                        }
                        
                        function cancelDelete() {
                            const modal = document.getElementById('deleteModal');
                            modal.classList.add('hidden');
                        }
                        
                        function openStockModal(id, reference, designation, stock) {
                            const modal = document.getElementById('stockModal');
                            const articleIdInput = document.getElementById('article_id');
                            const currentStockInput = document.getElementById('current_stock');
                            const articleInfoSpan = document.getElementById('article_info');
                            const stockInfoSpan = document.getElementById('stock_info');
                            const quantiteModificationInput = document.getElementById('quantite_modification');
                            
                            modal.classList.remove('hidden');
                            articleIdInput.value = id;
                            currentStockInput.value = stock;
                            articleInfoSpan.textContent = reference + ' - ' + designation;
                            stockInfoSpan.textContent = stock;
                            quantiteModificationInput.value = stock;
                            
                            // Reset to default view (reception)
                            document.getElementById('type_mouvement').value = 'reception';
                            toggleMouvementOptions();
                        }
                        
                        function closeStockModal() {
                            const modal = document.getElementById('stockModal');
                            modal.classList.add('hidden');
                        }
                        
                        function toggleMouvementOptions() {
                            const typeMouvement = document.getElementById('type_mouvement').value;
                            const receptionFields = document.getElementById('reception_fields');
                            const modificationFields = document.getElementById('modification_fields');
                            const retourFields = document.getElementById('retour_fields');
                            
                            // Cacher tous les champs
                            receptionFields.style.display = 'none';
                            modificationFields.style.display = 'none';
                            retourFields.style.display = 'none';
                            
                            // Afficher les champs appropriés selon le type de mouvement
                            if (typeMouvement === 'reception') {
                                receptionFields.style.display = 'block';
                            } else if (typeMouvement === 'modification') {
                                modificationFields.style.display = 'block';
                            } else if (typeMouvement === 'retour') {
                                retourFields.style.display = 'block';
                            }
                        }
                        
                        // Close modals when clicking outside
                        document.addEventListener('click', function(event) {
                            const deleteModal = document.getElementById('deleteModal');
                            const stockModal = document.getElementById('stockModal');
                            
                            if (event.target === deleteModal) {
                                cancelDelete();
                            }
                            
                            if (event.target === stockModal) {
                                closeStockModal();
                            }
                        });
                    </script>
                </div>
            </div>
        </div>
        
        <?php include $root_path . '/includes/footer.php'; ?>
