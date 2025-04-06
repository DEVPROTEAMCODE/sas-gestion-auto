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

// Connexion à la base de données
$database = new Database();
$db = $database->getConnection();

// Vérifier si la connexion est bien établie
if (!$db) {
    die("Erreur de connexion à la base de données.");
}

// Vérifier si l'utilisateur est connecté, sinon créer un utilisateur fictif pour le développement
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login.php");
    exit;
}

// Récupérer les informations de l'utilisateur connecté
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

// Configuration de la pagination
$clients_per_page = 6;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $clients_per_page;

// Déterminer l'onglet actif
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'client';
if (!in_array($active_tab, ['client', 'societe'])) {
    $active_tab = 'client';
}

// Traitement de la recherche
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$search_condition = '';
if (!empty($search)) {
    // Adapter les conditions de recherche selon le type de client
    if ($active_tab == 'client') {
        $search_condition = " AND (
            c.nom LIKE :search OR 
            c.prenom LIKE :search OR
            c.email LIKE :search OR 
            c.telephone LIKE :search OR 
            c.ville LIKE :search
        )";
    } else {
        // Pour les sociétés, ne pas inclure le prénom/nom dans la recherche
        $search_condition = " AND (
            c.raison_sociale LIKE :search OR 
            c.registre_rcc LIKE :search OR
            c.email LIKE :search OR 
            c.telephone LIKE :search OR 
            c.ville LIKE :search
        )";
    }
}

// Récupérer le nombre total de clients selon le type
$count_query = "SELECT COUNT(*) as total FROM clients c 
                JOIN type_client tc ON c.type_client_id = tc.id 
                WHERE tc.type = :type_client" . $search_condition;

$count_stmt = $db->prepare($count_query);
$count_stmt->bindParam(':type_client', $active_tab, PDO::PARAM_STR);
if (!empty($search)) {
    $search_param = "%$search%";
    $count_stmt->bindParam(':search', $search_param, PDO::PARAM_STR);
}
$count_stmt->execute();
$total_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
$total_clients = $total_result['total'];
$total_pages = ceil($total_clients / $clients_per_page);

// Récupérer les clients pour la page courante selon le type
if ($active_tab == 'client') {
    $query = "SELECT c.id, c.nom, c.prenom, c.adresse, c.code_postal, c.ville, c.telephone, c.email, c.date_creation, c.notes,
                   (SELECT COUNT(*) FROM vehicules v WHERE v.client_id = c.id) AS nb_vehicules
              FROM clients c 
              JOIN type_client tc ON c.type_client_id = tc.id 
              WHERE tc.type = :type_client" . $search_condition . "
              ORDER BY c.date_creation DESC
              LIMIT :limit OFFSET :offset";
} else {
    $query = "SELECT c.id, c.nom, c.prenom, c.raison_sociale, c.registre_rcc, c.adresse, c.code_postal, c.ville, c.telephone, c.email, c.date_creation, c.notes,
                   (SELECT COUNT(*) FROM vehicules v WHERE v.client_id = c.id) AS nb_vehicules
              FROM clients c 
              JOIN type_client tc ON c.type_client_id = tc.id 
              WHERE tc.type = :type_client" . $search_condition . "
              ORDER BY c.date_creation DESC
              LIMIT :limit OFFSET :offset";
}

$stmt = $db->prepare($query);
$stmt->bindParam(':type_client', $active_tab, PDO::PARAM_STR);
if (!empty($search)) {
    $search_param = "%$search%";
    $stmt->bindParam(':search', $search_param, PDO::PARAM_STR);
}
$stmt->bindParam(':limit', $clients_per_page, PDO::PARAM_INT);
$stmt->bindParam(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$clients = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
                <h1 class="text-2xl font-semibold text-gray-800">Gestion des Clients</h1>
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <span class="text-gray-700"><?php echo isset($currentUser['name']) ? htmlspecialchars($currentUser['name']) : 'Utilisateur'; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Clients Content -->
        <div class="container mx-auto px-6 py-8">
            <!-- Search and Add Button -->
            <div class="flex flex-col md:flex-row md:justify-between md:items-center mb-6">
                <div class="flex-1">
                    <form action="" method="GET" class="mb-4 md:mb-0">
                        <input type="hidden" name="tab" value="<?php echo $active_tab; ?>">
                        <div class="relative">
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                placeholder="<?php echo $active_tab == 'client' ? 'Rechercher un client par nom, prénom, email...' : 'Rechercher une société par raison sociale, RCC, email...'; ?>" 
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                            <button type="submit" class="absolute right-0 top-0 h-full px-4 text-gray-600 hover:text-indigo-500">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                </svg>
                            </button>
                        </div>
                    </form>
                </div>
                <div class="flex space-x-3">
                    <a href="create.php<?php echo $active_tab == 'societe' ? '?type=societe' : ''; ?>" class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition flex items-center">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Ajouter <?php echo $active_tab == 'client' ? 'un client' : 'une société'; ?>
                    </a>
                    <button type="button" class="px-4 py-2 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition flex items-center">
                        <svg class="w-5 h-5 mr-2 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path>
                        </svg>
                        Filtrer
                    </button>
                </div>
            </div>

            <!-- Tabs -->
            <div class="mb-6 border-b border-gray-200">
                <ul class="flex -mb-px">
                    <li class="mr-1">
                        <a href="?tab=client<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="<?php echo $active_tab == 'client' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> inline-block py-4 px-6 border-b-2 font-medium text-sm">
                            Clients particuliers
                        </a>
                    </li>
                    <li class="mr-1">
                        <a href="?tab=societe<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="<?php echo $active_tab == 'societe' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'; ?> inline-block py-4 px-6 border-b-2 font-medium text-sm">
                            Sociétés
                        </a>
                    </li>
                </ul>
            </div>

            <!-- Clients Table -->
            <div class="bg-white rounded-lg shadow overflow-hidden">
                <div class="overflow-x-auto">
                    <?php if ($active_tab == 'client'): ?>
                        <!-- Table for individual clients -->
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Client
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Contact
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Adresse
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Date de création
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Véhicules
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($clients)): ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                                            Aucun client particulier trouvé
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($clients as $client): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <div class="flex-shrink-0 h-10 w-10">
                                                        <div class="h-10 w-10 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-600 font-semibold">
                                                            <?php echo substr($client['prenom'], 0, 1) . substr($client['nom'], 0, 1); ?>
                                                        </div>
                                                    </div>
                                                    <div class="ml-4">
                                                        <div class="text-sm font-medium text-gray-900">
                                                            <?php echo htmlspecialchars($client['prenom'] . ' ' . $client['nom']); ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm text-gray-900"><?php echo htmlspecialchars($client['email']); ?></div>
                                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($client['telephone']); ?></div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm text-gray-900"><?php echo htmlspecialchars($client['adresse']); ?></div>
                                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($client['code_postal'] . ' ' . $client['ville']); ?></div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                <?php echo date('d/m/Y', strtotime($client['date_creation'])); ?>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $client['nb_vehicules'] > 0 ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'; ?>">
                                                    <?php echo $client['nb_vehicules']; ?> véhicule<?php echo $client['nb_vehicules'] > 1 ? 's' : ''; ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                <div class="flex justify-end space-x-2">
                                                    <a href="view.php?id=<?php echo $client['id']; ?>" class="text-indigo-600 hover:text-indigo-900" title="Voir">
                                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                        </svg>
                                                    </a>
                                                    <a href="edit.php?id=<?php echo $client['id']; ?>" class="text-blue-600 hover:text-blue-900" title="Modifier">
                                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                        </svg>
                                                    </a>
                                                    <a href="javascript:void(0)" onclick="Delete(<?php echo $client['id']; ?>)" class="text-red-600 hover:text-red-900" title="Supprimer">
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
                    <?php else: ?>
                        <!-- Table for companies -->
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Société
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Registre RCC
                                    </th>
                                 <!--    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Contact
                                    </th> -->
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Adresse
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Véhicules
                                    </th>
                                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php if (empty($clients)): ?>
                                    <tr>
                                        <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                                            Aucune société trouvée
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($clients as $client): ?>
                                        <tr class="hover:bg-gray-50">
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="flex items-center">
                                                    <div class="flex-shrink-0 h-10 w-10">
                                                        <div class="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 font-semibold">
                                                            <?php 
                                                            // Générer des initiales pour la société
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
                                                            ?>
                                                        </div>
                                                    </div>
                                                    <div class="ml-4">
                                                        <div class="text-sm font-medium text-gray-900">
                                                            <?php echo htmlspecialchars($client['raison_sociale']); ?>
                                                        </div>
                                                       <!--  <?php if (!empty($client['prenom']) || !empty($client['nom'])): ?>
                                                        <div class="text-xs text-gray-500">
                                                            Contact: <?php echo htmlspecialchars(trim($client['prenom'] . ' ' . $client['nom'])); ?>
                                                        </div>
                                                        <?php endif; ?> -->
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm text-gray-900"><?php echo htmlspecialchars($client['registre_rcc']); ?></div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm text-gray-900"><?php echo htmlspecialchars($client['email']); ?></div>
                                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($client['telephone']); ?></div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <div class="text-sm text-gray-900"><?php echo htmlspecialchars($client['adresse']); ?></div>
                                                <div class="text-sm text-gray-500"><?php echo htmlspecialchars($client['code_postal'] . ' ' . $client['ville']); ?></div>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap">
                                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $client['nb_vehicules'] > 0 ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'; ?>">
                                                    <?php echo $client['nb_vehicules']; ?> véhicule<?php echo $client['nb_vehicules'] > 1 ? 's' : ''; ?>
                                                </span>
                                            </td>
                                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                                <div class="flex justify-end space-x-2">
                                                    <a href="view.php?id=<?php echo $client['id']; ?>" class="text-indigo-600 hover:text-indigo-900" title="Voir">
                                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                                                        </svg>
                                                    </a>
                                                    <a href="edit.php?id=<?php echo $client['id']; ?>" class="text-blue-600 hover:text-blue-900" title="Modifier">
                                                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                                                        </svg>
                                                    </a>
                                                    <a href="javascript:void(0)" onclick="Delete(<?php echo $client['id']; ?>)" class="text-red-600 hover:text-red-900" title="Supprimer">
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
                    <?php endif; ?>
                </div>
            </div>

            <!-- Pagination -->
            <div class="mt-6 flex justify-between items-center">
                <div class="text-sm text-gray-500">
                    Affichage de <span class="font-medium">
                        <?php 
                            $start_count = $total_clients > 0 ? $offset + 1 : 0;
                            $end_count = min($total_clients, $offset + count($clients));
                            echo $start_count . '-' . $end_count;
                        ?>
                    </span> 
                    <?php echo $active_tab == 'client' ? 'client(s)' : 'société(s)'; ?> sur <span class="font-medium"><?php echo $total_clients; ?></span> au total
                </div>
                
                <?php if ($total_pages > 1): ?>
                    <div class="flex space-x-2">
                        <!-- Bouton Précédent -->
                        <?php if ($current_page > 1): ?>
                            
                            <a href="?tab=<?php echo $active_tab; ?>&page=<?php echo $current_page - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
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
                                <a href="?tab=<?php echo $active_tab; ?>&page=<?php echo $i; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                                   class="px-3 py-1 rounded border border-gray-300 text-gray-700 hover:bg-gray-50">
                                    <?php echo $i; ?>
                                </a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <!-- Bouton Suivant -->
                        <?php if ($current_page < $total_pages): ?>
                            <a href="?tab=<?php echo $active_tab; ?>&page=<?php echo $current_page + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
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

<!-- Delete Confirmation Modal -->
<div id="deleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 flex items-center justify-center hidden z-50">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full">
        <div class="px-6 py-4 border-b">
            <h3 class="text-lg font-semibold text-gray-900">Confirmation de suppression</h3>
        </div>
        <div class="px-6 py-4">
            <p class="text-gray-700">Êtes-vous sûr de vouloir supprimer ce <?php echo $active_tab == 'client' ? 'client' : 'cette société'; ?> ? Cette action est irréversible.</p>
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

<!-- JavaScript for delete confirmation -->
<script>
    function Delete(id) {
        const modal = document.getElementById('deleteModal');
        const confirmBtn = document.getElementById('confirmDeleteBtn');
        
        modal.classList.remove('hidden');
        confirmBtn.href = 'delete.php?id=' + id;
    }
    
    function cancelDelete() {
        const modal = document.getElementById('deleteModal');
        modal.classList.add('hidden');
    }
    
    // Close modal when clicking outside
    document.addEventListener('click', function(event) {
        const modal = document.getElementById('deleteModal');
        if (event.target === modal) {
            cancelDelete();
        }
    });
</script>

<?php include $root_path . '/includes/footer.php'; ?>
