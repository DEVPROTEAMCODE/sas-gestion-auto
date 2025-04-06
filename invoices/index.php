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
try {
    $database = new Database();
    $db = $database->getConnection();
    
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

$database = new Database();
$db = $database->getConnection();

// Traitement des actions
$message = '';
$success = false;

// Traiter la suppression d'une facture
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    try {
        // Démarrer une transaction
        $db->beginTransaction();
        
        // Supprimer d'abord les détails de la facture
        $deleteDetailsQuery = "DELETE FROM facture_details WHERE ID_Facture = :id";
        $deleteDetailsStmt = $db->prepare($deleteDetailsQuery);
        $deleteDetailsStmt->bindParam(':id', $_GET['delete']);
        $deleteDetailsStmt->execute();
        
        // Ensuite supprimer la facture
        $deleteFactureQuery = "DELETE FROM factures WHERE id = :id";
        $deleteFactureStmt = $db->prepare($deleteFactureQuery);
        $deleteFactureStmt->bindParam(':id', $_GET['delete']);
        $deleteFactureStmt->execute();
        
        // Valider la transaction
        $db->commit();
        
        $success = true;
        $message = "La facture a été supprimée avec succès.";
    } catch (PDOException $e) {
        // En cas d'erreur, annuler la transaction
        $db->rollBack();
        $message = "Erreur lors de la suppression de la facture: " . $e->getMessage();
    }
}

// Traiter le changement de statut d'une facture
if (isset($_GET['mark_paid']) && is_numeric($_GET['mark_paid'])) {
    try {
        $updateQuery = "UPDATE factures SET Statut_Facture = 'Payée', Date_Paiement = :date_paiement, Updated_At = :updated_at WHERE id = :id";
        $updateStmt = $db->prepare($updateQuery);
        
        $currentDate = date('Y-m-d H:i:s');
        
        $updateStmt->bindParam(':date_paiement', $currentDate);
        $updateStmt->bindParam(':updated_at', $currentDate);
        $updateStmt->bindParam(':id', $_GET['mark_paid']);
        
        if ($updateStmt->execute()) {
            $success = true;
            $message = "La facture a été marquée comme payée.";
        } else {
            $message = "Erreur lors de la mise à jour du statut de la facture.";
        }
    } catch (PDOException $e) {
        $message = "Erreur de base de données: " . $e->getMessage();
    }
}

// Traiter la validation d'une facture
if (isset($_GET['validate']) && is_numeric($_GET['validate'])) {
    try {
        $updateQuery = "UPDATE factures SET Statut_Facture = 'Validée', Updated_At = :updated_at WHERE id = :id";
        $updateStmt = $db->prepare($updateQuery);
        
        $currentDate = date('Y-m-d H:i:s');
        
        $updateStmt->bindParam(':updated_at', $currentDate);
        $updateStmt->bindParam(':id', $_GET['validate']);
        
        if ($updateStmt->execute()) {
            $success = true;
            $message = "La facture a été validée avec succès.";
        } else {
            $message = "Erreur lors de la validation de la facture.";
        }
    } catch (PDOException $e) {
        $message = "Erreur de base de données: " . $e->getMessage();
    }
}

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Filtres
$where = "1=1";
$params = [];

if (isset($_GET['numero']) && !empty($_GET['numero'])) {
    $where .= " AND Numero_Facture = :numero";
    $params[':numero'] = $_GET['numero'];
}

if (isset($_GET['commande']) && !empty($_GET['commande'])) {
    $where .= " AND ID_Commande = :commande";
    $params[':commande'] = $_GET['commande'];
}

if (isset($_GET['search']) && !empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $where .= " AND (Notes LIKE :search)";
    $params[':search'] = $search;
}

if (isset($_GET['status']) && !empty($_GET['status'])) {
    $where .= " AND Statut_Facture = :status";
    $params[':status'] = $_GET['status'];
}

if (isset($_GET['client']) && !empty($_GET['client'])) {
    $where .= " AND ID_Client = :client";
    $params[':client'] = $_GET['client'];
}

if (isset($_GET['date_from']) && !empty($_GET['date_from'])) {
    $where .= " AND Date_Facture >= :date_from";
    $params[':date_from'] = $_GET['date_from'];
}

if (isset($_GET['date_to']) && !empty($_GET['date_to'])) {
    $where .= " AND Date_Facture <= :date_to";
    $params[':date_to'] = $_GET['date_to'];
}

// Statistiques des factures
$statsQuery = "SELECT 
                COUNT(*) as total_factures,
                SUM(CASE WHEN Statut_Facture = 'Payée' THEN 1 ELSE 0 END) as factures_payees,
                SUM(CASE WHEN Statut_Facture = 'En attente' THEN 1 ELSE 0 END) as factures_en_attente,
                SUM(CASE WHEN Statut_Facture = 'En retard' THEN 1 ELSE 0 END) as factures_en_retard,
                SUM(Montant_Total_HT) as montant_total_ht,
                SUM(CASE WHEN Statut_Facture = 'Payée' THEN Montant_Total_HT ELSE 0 END) as montant_paye,
                SUM(CASE WHEN Statut_Facture != 'Payée' THEN Montant_Total_HT ELSE 0 END) as montant_non_paye
            FROM factures";
$statsStmt = $db->prepare($statsQuery);
$statsStmt->execute();
$stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

// Récupérer le nombre total de factures
$countQuery = "SELECT COUNT(*) FROM factures WHERE $where";
$countStmt = $db->prepare($countQuery);
foreach ($params as $key => $value) {
    $countStmt->bindValue($key, $value);
}
$countStmt->execute();
$totalFactures = $countStmt->fetchColumn();

// Calculer le nombre total de pages
$totalPages = ceil($totalFactures / $limit);

// Récupérer les factures avec les informations des clients
// Récupérer les factures avec les informations des clients
$query = "SELECT f.*, c.nom as client_nom, c.prenom as client_prenom, 
          c.type_client_id, c.raison_sociale
          FROM factures f 
          LEFT JOIN clients c ON f.ID_Client = c.id 
          WHERE $where 
          ORDER BY f.Date_Facture DESC 
          LIMIT :limit OFFSET :offset";


$stmt = $db->prepare($query);
$stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
$stmt->bindParam(':offset', $offset, PDO::PARAM_INT);

foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}

$stmt->execute();
$factures = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer tous les clients pour le filtre
$clientsQuery = "SELECT * FROM clients ORDER BY nom";
$clientsStmt = $db->prepare($clientsQuery);
$clientsStmt->execute();
$clients = $clientsStmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer les factures impayées pour les clients normaux pour les notifications
$notificationsQuery = "SELECT f.id, f.Numero_Facture, f.Date_Facture, f.Date_Facture, 
                      c.nom as client_nom, c.prenom as client_prenom
                      FROM factures f
                      JOIN clients c ON f.ID_Client = c.id
                      WHERE f.Statut_Facture = 'En attente'
                      AND c.type_client_id = 0
                      AND f.Date_Facture < CURDATE()
                      ORDER BY f.Date_Facture ASC";
$notificationsStmt = $db->prepare($notificationsQuery);
$notificationsStmt->execute();
$unpaidInvoices = $notificationsStmt->fetchAll(PDO::FETCH_ASSOC);

// Inclure l'en-tête
include $root_path . '/includes/header.php';
?>

<div class="flex h-screen bg-gray-100">
    <!-- Sidebar -->
    <?php include $root_path . '/includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 overflow-auto p-6">
    <div class="bg-white shadow-sm">
            <div class="container mx-auto px-6 py-4 flex justify-between items-center">
                <h1 class="text-2xl font-semibold text-gray-800">Gestion des Factures</h1>
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <span class="text-gray-700"><?php echo htmlspecialchars($currentUser['prenom'] . ' ' . $currentUser['nom']); ?></span>
                    </div>
                </div>
            </div>
        </div>
        <div class="bg-white rounded-lg shadow-md p-6">
            <div class="flex justify-between items-center mb-6">
                <a href="../orders/create.php" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 inline-block mr-1" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" clip-rule="evenodd" />
                    </svg>
                    Nouvelle Facture
                </a>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="<?php echo $success ? 'bg-green-100 border-green-400 text-green-700' : 'bg-red-100 border-red-400 text-red-700'; ?> px-4 py-3 rounded mb-4 border">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <!-- Statistiques des factures -->
            <div class="mb-6 p-4 bg-gray-50 rounded-md">
                <h3 class="text-lg font-medium text-gray-700 mb-3">Statistiques</h3>
                <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div class="bg-white p-4 rounded-lg shadow">
                        <div class="flex items-center">
                            <div class="flex-shrink-0 bg-blue-500 rounded-md p-3">
                                <svg class="h-6 w-6 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                </svg>
                            </div>
                            <div class="ml-4">
                                <div class="text-sm font-medium text-gray-500">Total factures</div>
                                <div class="text-lg font-semibold text-gray-900"><?php echo $stats['total_factures']; ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white p-4 rounded-lg shadow">
                        <div class="flex items-center">
                            <div class="flex-shrink-0 bg-green-500 rounded-md p-3">
                                <svg class="h-6 w-6 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div class="ml-4">
                                <div class="text-sm font-medium text-gray-500">Factures payées</div>
                                <div class="text-lg font-semibold text-gray-900"><?php echo $stats['factures_payees']; ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white p-4 rounded-lg shadow">
                        <div class="flex items-center">
                            <div class="flex-shrink-0 bg-yellow-500 rounded-md p-3">
                                <svg class="h-6 w-6 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div class="ml-4">
                                <div class="text-sm font-medium text-gray-500">Factures en attente</div>
                                <div class="text-lg font-semibold text-gray-900"><?php echo $stats['factures_en_attente']; ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white p-4 rounded-lg shadow">
                        <div class="flex items-center">
                            <div class="flex-shrink-0 bg-red-500 rounded-md p-3">
                                <svg class="h-6 w-6 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                            </div>
                            <div class="ml-4">
                                <div class="text-sm font-medium text-gray-500">Factures en retard</div>
                                <div class="text-lg font-semibold text-gray-900"><?php echo $stats['factures_en_retard']; ?></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-white p-4 rounded-lg shadow">
                        <div class="flex items-center">
                            <div class="flex-shrink-0 bg-indigo-500 rounded-md p-3">
                                <svg class="h-6 w-6 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div class="ml-4">
                                <div class="text-sm font-medium text-gray-500">Montant total HT</div>
                                <div class="text-lg font-semibold text-gray-900"><?php echo number_format($stats['montant_total_ht'], 2, ',', ' '); ?> DH</div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-4 rounded-lg shadow">
                        <div class="flex items-center">
                            <div class="flex-shrink-0 bg-green-500 rounded-md p-3">
                                <svg class="h-6 w-6 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
                                </svg>
                            </div>
                            <div class="ml-4">
                                <div class="text-sm font-medium text-gray-500">Montant payé (HT)</div>
                                <div class="text-lg font-semibold text-gray-900"><?php echo number_format($stats['montant_paye'], 2, ',', ' '); ?> DH</div>
                            </div>
                        </div>
                    </div>

                    <div class="bg-white p-4 rounded-lg shadow">
                        <div class="flex items-center">
                            <div class="flex-shrink-0 bg-red-500 rounded-md p-3">
                                <svg class="h-6 w-6 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <div class="ml-4">
                                <div class="text-sm font-medium text-gray-500">Montant à recevoir (HT)</div>
                                <div class="text-lg font-semibold text-gray-900"><?php echo number_format($stats['montant_non_paye'], 2, ',', ' '); ?> DH</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filtres -->
            <div class="mb-6 p-4 bg-gray-50 rounded-md">
                <h3 class="text-lg font-medium text-gray-700 mb-3">Filtres</h3>
                <form method="GET" action="" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div>
                        <label for="numero" class="block text-sm font-medium text-gray-700 mb-1">Numéro de facture</label>
                        <input type="text" id="numero" name="numero" placeholder="Numéro de facture..." 
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                            value="<?php echo isset($_GET['numero']) ? htmlspecialchars($_GET['numero']) : ''; ?>">
                    </div>
                    <div>
                        <label for="commande" class="block text-sm font-medium text-gray-700 mb-1">ID Commande</label>
                        <input type="text" id="commande" name="commande" placeholder="ID Commande..." 
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                            value="<?php echo isset($_GET['commande']) ? htmlspecialchars($_GET['commande']) : ''; ?>">
                    </div>
                    <div>
                        <label for="search" class="block text-sm font-medium text-gray-700 mb-1">Recherche</label>
                        <input type="text" id="search" name="search" placeholder="Notes..." 
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                            value="<?php echo isset($_GET['search']) ? htmlspecialchars($_GET['search']) : ''; ?>">
                    </div>
                    <!-- Clients dropdown -->
<div>
    <label for="client" class="block text-sm font-medium text-gray-700 mb-1">Client</label>
    <select id="client" name="client" 
        class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
        <option value="">Tous les clients</option>
        <?php 
        $clients = [];
        try {
            $query = "SELECT id, 
                             CASE 
                                WHEN type_client_id = 1 THEN CONCAT(prenom, ' ', nom)
                                ELSE CONCAT(nom, ' - ', raison_sociale)
                             END AS Nom_Client
                      FROM clients 
                      ORDER BY Nom_Client";
            $stmt = $db->prepare($query);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } 
        } catch (PDOException $e) {
            $errors['database'] = 'Erreur lors de la récupération des clients: ' . $e->getMessage();
        }
        
        foreach ($clients as $client): 
            $selected = (isset($_GET['client']) && $_GET['client'] == $client['id']) ? 'selected' : '';
        ?>
            <option value="<?php echo $client['id']; ?>" <?php echo $selected; ?>>
                <?php echo htmlspecialchars($client['Nom_Client']); ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

                    <div>
                        <label for="status" class="block text-sm font-medium text-gray-700 mb-1">Statut</label>
                        <select id="status" name="status" 
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                            <option value="">Tous les statuts</option>
                            <?php 
                            $statuts = ['En attente', 'Payée', 'Annulée', 'En retard', 'Validée'];
                            foreach ($statuts as $statut):
                                $selected = (isset($_GET['status']) && $_GET['status'] === $statut) ? 'selected' : '';
                                echo "<option value=\"$statut\" $selected>$statut</option>";
                            endforeach;
                            ?>
                        </select>
                    </div>
                  
                    <div>
                        <label for="date_to" class="block text-sm font-medium text-gray-700 mb-1">Date Facture</label>
                        <input type="date" id="date_to" name="date_to" 
                            class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                            value="<?php echo isset($_GET['date_to']) ? htmlspecialchars($_GET['date_to']) : ''; ?>">
                    </div>
                    <div class="md:col-span-2 flex items-end">
                        <button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 mr-2">
                            Filtrer
                        </button>
                        <a href="index.php" class="px-4 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500">
                            Réinitialiser
                        </a>
                    </div>
                </form>
            </div>
            
            <!-- Notifications pour les factures impayées des clients normaux -->
            <?php if (count($unpaidInvoices) > 0): ?>
                <div class="mb-6 p-4 bg-yellow-50 border border-yellow-400 rounded-md">
                    <div class="flex items-center mb-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-yellow-600 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                        <h3 class="text-lg font-medium text-yellow-700">Factures impayées pour clients normaux</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Numéro</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Client</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date Facture</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date Échéance</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <?php foreach ($unpaidInvoices as $invoice): ?>
                                    <tr>
                                        <td class="px-4 py-2 whitespace-nowrap text-sm font-medium text-gray-900">
                                            <?php echo htmlspecialchars($invoice['Numero_Facture']); ?>
                                        </td>
                                        <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo htmlspecialchars($invoice['client_nom'] . ' ' . $invoice['client_prenom']); ?>
                                        </td>
                                        <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500">
                                            <?php echo date('d/m/Y', strtotime($invoice['Date_Facture'])); ?>
                                        </td>
                                        <td class="px-4 py-2 whitespace-nowrap text-sm text-red-600 font-medium">
                                            <?php echo date('d/m/Y', strtotime($invoice['Date_Facture'])); ?>
                                        </td>
                                        <td class="px-4 py-2 whitespace-nowrap text-sm font-medium">
                                            <div class="flex space-x-2">
                                                <a href="view_facture.php?id=<?php echo $invoice['id']; ?>" class="text-blue-600 hover:text-blue-900" title="Voir">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                    </svg>
                                                </a>
                                                <a href="index.php?mark_paid=<?php echo $invoice['id']; ?>" class="text-green-600 hover:text-green-900" title="Marquer comme payée" onclick="return confirm('Marquer cette facture comme payée?');">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    </svg>
                                                </a>
                                                <a href="index.php?validate=<?php echo $invoice['id']; ?>" class="text-indigo-600 hover:text-indigo-900" title="Valider la facture" onclick="return confirm('Valider cette facture?');">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                                    </svg>
                                                </a>
                                                <a href="send_invoice_email.php?id=<?php echo $invoice['id']; ?>" class="text-blue-500 hover:text-blue-700" title="Envoyer un rappel par email" onclick="return confirm('Envoyer un rappel de paiement par email au client?');">
                                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                                    </svg>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Tableau des factures -->
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Numéro</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Client</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID Commande</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Montant HT</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Statut</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (count($factures) > 0): ?>
                            <?php foreach ($factures as $facture): ?>
                                <tr class="<?php echo ($facture['Statut_Facture'] == 'En attente' && $facture['type_client_id'] == 0 && strtotime($facture['Date_Facture']) < time()) ? 'bg-red-50' : ''; ?>">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        <?php echo htmlspecialchars($facture['Numero_Facture']); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo date('d/m/Y', strtotime($facture['Date_Facture'])); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
    <?php 
    // Get client name using the same formatting as in the dropdown
    $clientName = "";
    if ($facture['type_client_id'] == 1) {
        $clientName = htmlspecialchars($facture['client_prenom'] . ' ' . $facture['client_nom']);
    } else {
        $clientName = htmlspecialchars($facture['client_nom'] . ' - ' . $facture['raison_sociale']);
    }
    echo $clientName;
    ?>
</td>

                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo htmlspecialchars($facture['ID_Commande'] ?? 'N/A'); ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?php echo number_format($facture['Montant_Total_HT'], 2, ',', ' '); ?> DH
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full 
                                            <?php 
                                            switch($facture['Statut_Facture']) {
                                                case 'Payée':
                                                    echo 'bg-green-100 text-green-800';
                                                    break;
                                                case 'En attente':
                                                    echo 'bg-yellow-100 text-yellow-800';
                                                    break;
                                                case 'En retard':
                                                    echo 'bg-red-100 text-red-800';
                                                    break;
                                                case 'Annulée':
                                                    echo 'bg-gray-100 text-gray-800';
                                                    break;
                                                case 'Validée':
                                                    echo 'bg-indigo-100 text-indigo-800';
                                                    break;
                                                default:
                                                    echo 'bg-blue-100 text-blue-800';
                                            }
                                            ?>">
                                            <?php echo htmlspecialchars($facture['Statut_Facture']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
    <div class="flex space-x-2">
        <a href="view_facture.php?id=<?php echo $facture['id']; ?>" class="text-blue-600 hover:text-blue-900" title="Voir">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
            </svg>
        </a>
        
        <?php if ($facture['Statut_Facture'] === 'En attente'): ?>
            <!-- Pour les factures en attente, afficher uniquement l'option de valider -->
            <a href="index.php?validate=<?php echo $facture['id']; ?>" class="text-indigo-600 hover:text-indigo-900" title="Valider la facture" onclick="return confirm('Valider cette facture?');">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
            </a>
        <?php elseif ($facture['Statut_Facture'] === 'Validée'): ?>
            <!-- Pour les factures validées, afficher l'option de marquer comme payée -->
            <a href="index.php?mark_paid=<?php echo $facture['id']; ?>" class="text-green-600 hover:text-green-900" title="Marquer comme payée" onclick="return confirm('Marquer cette facture comme payée?');">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </a>
        <?php endif; ?>
        
        <a href="edit_facture.php?id=<?php echo $facture['id']; ?>" class="text-indigo-600 hover:text-indigo-900" title="Modifier">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
            </svg>
        </a>
        
        <a href="factures.php?delete=<?php echo $facture['id']; ?>" class="text-red-600 hover:text-red-900" title="Supprimer" onclick="return confirm('Êtes-vous sûr de vouloir supprimer cette facture?');">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
        </a>
        
        <a href="print_facture.php?id=<?php echo $facture['id']; ?>" class="text-gray-600 hover:text-gray-900" title="Imprimer" target="_blank">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z" />
            </svg>
        </a>
        <a href="send_invoice_email.php?id=<?php echo $facture['id']; ?>" class="text-blue-500 hover:text-blue-700" title="Envoyer par email" onclick="return confirm('Envoyer cette facture par email au client?');">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
            </svg>
        </a>
    </div>
</td>

                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="px-6 py-4 text-center text-sm text-gray-500">
                                    Aucune facture trouvée.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <div class="mt-6 flex justify-center">
                    <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?php echo $page - 1; ?><?php echo isset($_GET['numero']) ? '&numero='.urlencode($_GET['numero']) : ''; ?><?php echo isset($_GET['commande']) ? '&commande='.urlencode($_GET['commande']) : ''; ?><?php echo isset($_GET['search']) ? '&search='.urlencode($_GET['search']) : ''; ?><?php echo isset($_GET['status']) ? '&status='.urlencode($_GET['status']) : ''; ?><?php echo isset($_GET['client']) ? '&client='.urlencode($_GET['client']) : ''; ?><?php echo isset($_GET['date_from']) ? '&date_from='.urlencode($_GET['date_from']) : ''; ?><?php echo isset($_GET['date_to']) ? '&date_to='.urlencode($_GET['date_to']) : ''; ?>" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                <span class="sr-only">Précédent</span>
                                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z" clip-rule="evenodd" />
                                </svg>
                            </a>
                        <?php endif; ?>
                        
                        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <a href="?page=<?php echo $i; ?><?php echo isset($_GET['numero']) ? '&numero='.urlencode($_GET['numero']) : ''; ?><?php echo isset($_GET['commande']) ? '&commande='.urlencode($_GET['commande']) : ''; ?><?php echo isset($_GET['search']) ? '&search='.urlencode($_GET['search']) : ''; ?><?php echo isset($_GET['status']) ? '&status='.urlencode($_GET['status']) : ''; ?><?php echo isset($_GET['client']) ? '&client='.urlencode($_GET['client']) : ''; ?><?php echo isset($_GET['date_from']) ? '&date_from='.urlencode($_GET['date_from']) : ''; ?><?php echo isset($_GET['date_to']) ? '&date_to='.urlencode($_GET['date_to']) : ''; ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium <?php echo $i === $page ? 'text-blue-600 bg-blue-50' : 'text-gray-700 hover:bg-gray-50'; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?php echo $page + 1; ?><?php echo isset($_GET['numero']) ? '&numero='.urlencode($_GET['numero']) : ''; ?><?php echo isset($_GET['commande']) ? '&commande='.urlencode($_GET['commande']) : ''; ?><?php echo isset($_GET['search']) ? '&search='.urlencode($_GET['search']) : ''; ?><?php echo isset($_GET['status']) ? '&status='.urlencode($_GET['status']) : ''; ?><?php echo isset($_GET['client']) ? '&client='.urlencode($_GET['client']) : ''; ?><?php echo isset($_GET['date_from']) ? '&date_from='.urlencode($_GET['date_from']) : ''; ?><?php echo isset($_GET['date_to']) ? '&date_to='.urlencode($_GET['date_to']) : ''; ?>" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                <span class="sr-only">Suivant</span>
                                <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                                </svg>
                            </a>
                        <?php endif; ?>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Script pour mettre en évidence les factures en retard -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Mettre à jour automatiquement le statut des factures en retard
    const today = new Date();
    const rows = document.querySelectorAll('table tbody tr');
    
    rows.forEach(row => {
        const statusCell = row.querySelector('td:nth-child(6) span');
        if (statusCell && statusCell.textContent.trim() === 'En attente') {
            // Vérifier si la facture a une date d'échéance et si elle est dépassée
            // Cette logique devrait être gérée côté serveur, mais nous ajoutons une vérification côté client
            // pour les factures qui pourraient avoir changé de statut depuis le chargement de la page
            if (row.classList.contains('bg-red-50')) {
                statusCell.textContent = 'En retard';
                statusCell.classList.remove('bg-yellow-100', 'text-yellow-800');
                statusCell.classList.add('bg-red-100', 'text-red-800');
            }
        }
    });
});
</script>

<?php
// Inclure le pied de page
include $root_path . '/includes/footer.php';
?>
