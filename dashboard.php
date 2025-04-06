<?php
// Démarrer la session d'abord
session_start();

// Chemin racine de l'application
$root_path = __DIR__;

// Inclure les fichiers de configuration et de fonctions
if (file_exists($root_path . '/config/database.php')) {
    require_once $root_path . '/config/database.php';
} /* else {
    // Si le fichier n'existe pas, on crée un $currentUser par défaut
    // pour éviter les erreurs
    $currentUser = [
        'name' => 'Utilisateur',
        'role' => 'Admin'
    ];
} */

if (file_exists($root_path . '/includes/functions.php')) {
    require_once $root_path . '/includes/functions.php';
}
$database = new Database();
$db = $database->getConnection();
// Vérifier si l'utilisateur est connecté, sinon rediriger vers la page de connexion
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
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
include 'includes/header.php';

$count_query = "SELECT COUNT(*) as total FROM clients";
$count_stmt = $db->prepare($count_query);
$count_stmt->execute();
$total_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
$total_clients = $total_result['total'];

// Récupérer les alertes pour interventions à échéance proche (dans les 7 prochains jours)
$today = date('Y-m-d');
$next_week = date('Y-m-d', strtotime('+7 days'));

// Requête pour les interventions qui se terminent bientôt
$interventions_query = "SELECT i.id, i.description, i.date_fin, v.immatriculation, c.nom, c.prenom 
                       FROM interventions i 
                       LEFT JOIN vehicules v ON i.vehicule_id = v.id 
                       LEFT JOIN clients c ON i.client_id = c.id OR v.client_id = c.id
                       WHERE i.date_fin BETWEEN :today AND :next_week 
                       AND i.statut NOT IN ('Terminée', 'Facturée', 'Annulée')
                       ORDER BY i.date_fin ASC
                       LIMIT 5";
$interventions_stmt = $db->prepare($interventions_query);
$interventions_stmt->bindParam(':today', $today);
$interventions_stmt->bindParam(':next_week', $next_week);
$interventions_stmt->execute();
$interventions_alerts = $interventions_stmt->fetchAll(PDO::FETCH_ASSOC);

// Requête pour les factures non payées
$factures_query = "SELECT f.id, f.Numero_Facture, f.Date_Facture, f.Montant_Total_HT, c.nom, c.prenom 
                  FROM factures f 
                  JOIN clients c ON f.ID_Client = c.id 
                  WHERE f.Statut_Facture = 'Émise' 
                  ORDER BY f.Date_Facture ASC
                  LIMIT 5";
$factures_stmt = $db->prepare($factures_query);
$factures_stmt->execute();
$factures_alerts = $factures_stmt->fetchAll(PDO::FETCH_ASSOC);

// Requête pour les offres qui expirent bientôt
$offres_query = "SELECT id, nom, code, date_fin 
                FROM offres 
                WHERE date_fin BETWEEN :today AND :next_week 
                AND actif = 1
                ORDER BY date_fin ASC
                LIMIT 5";
$offres_stmt = $db->prepare($offres_query);
$offres_stmt->bindParam(':today', $today);
$offres_stmt->bindParam(':next_week', $next_week);
$offres_stmt->execute();
$offres_alerts = $offres_stmt->fetchAll(PDO::FETCH_ASSOC);

// Compter le nombre total d'alertes
$total_alerts = count($interventions_alerts) + count($factures_alerts) + count($offres_alerts);

// Récupérer les interventions pour le calendrier
$calendar_query = "SELECT i.id, i.date_prevue, i.description, i.statut, 
                  CONCAT(c.nom, ' ', c.prenom) AS client_nom,
                  v.immatriculation, CONCAT(v.marque, ' ', v.modele) AS vehicule_info
                  FROM interventions i
                  INNER JOIN vehicules v ON i.vehicule_id = v.id
                  INNER JOIN clients c ON v.client_id = c.id
                  WHERE i.date_prevue IS NOT NULL
                  ORDER BY i.date_prevue ASC";
$calendar_stmt = $db->prepare($calendar_query);
$calendar_stmt->execute();
$calendarData = $calendar_stmt->fetchAll(PDO::FETCH_ASSOC);

// Transformer les données pour le format du calendrier
$formattedData = [];
foreach ($calendarData as $intervention) {
    $date = substr($intervention['date_prevue'], 0, 10); // Format YYYY-MM-DD
    if (!isset($formattedData[$date])) {
        $formattedData[$date] = [];
    }
    $formattedData[$date][] = [
        'id' => $intervention['id'],
        'client' => $intervention['client_nom'],
        'description' => $intervention['description'],
        'statut' => $intervention['statut'],
        'vehicule' => $intervention['immatriculation']
    ];
}
$calendarInterventions = json_encode($formattedData);
// Récupérer les articles les plus consommés (basé sur l'historique des articles)
$top_articles_query = "
    SELECT 
        a.id,
        a.reference,
        a.designation,
        SUM(h.Quantite) as total_consomme,
        a.categorie_id,
        a.prix_vente_ht
    FROM 
        historique_articles h
    JOIN 
        articles a ON h.ID_Article = a.id
    WHERE 
        h.Type_Operation IN ('Commande', 'Retour') 
        AND h.Date_Operation >= DATE_SUB(CURRENT_DATE(), INTERVAL 30 DAY)
    GROUP BY 
        a.id, a.reference, a.designation, a.categorie_id, a.prix_vente_ht
    ORDER BY 
        total_consomme DESC
    LIMIT 10";


$top_articles_stmt = $db->prepare($top_articles_query);
$top_articles_stmt->execute();
$top_articles = $top_articles_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer la consommation par catégorie
$categories_query = "
    SELECT 
        c.id,
        c.nom as categorie_nom,
        SUM(h.Quantite) as total_consomme
    FROM 
        historique_articles h
    JOIN 
        articles a ON h.ID_Article = a.id
    JOIN 
        categorie c ON a.categorie_id = c.id
    WHERE 
        h.Type_Operation IN ('Commande', 'Retour')
        AND h.Date_Operation >= DATE_SUB(CURRENT_DATE(), INTERVAL 90 DAY)
    GROUP BY 
        c.id, c.nom
    ORDER BY 
        total_consomme DESC
    LIMIT 6";


$categories_stmt = $db->prepare($categories_query);
$categories_stmt->execute();
$top_categories = $categories_stmt->fetchAll(PDO::FETCH_ASSOC);

// Récupérer l'évolution des consommations sur les 6 derniers mois
$monthly_revenue_query = "
    SELECT 
        YEAR(Date_Facture) AS Année, 
        MONTH(Date_Facture) AS Mois, 
        SUM(Montant_Total_HT) AS Total_Mensuel 
    FROM 
        factures 
    GROUP BY 
        YEAR(Date_Facture), MONTH(Date_Facture) 
    ORDER BY 
        YEAR(Date_Facture), MONTH(Date_Facture)";

$monthly_revenue_stmt = $db->prepare($monthly_revenue_query);
$monthly_revenue_stmt->execute();
$monthly_revenue = $monthly_revenue_stmt->fetchAll(PDO::FETCH_ASSOC);

// Préparer les données pour les graphiques
$article_labels = [];
$article_data = [];
$article_colors = [];

foreach ($top_articles as $article) {
    $article_labels[] = substr($article['designation'], 0, 20) . (strlen($article['designation']) > 20 ? '...' : '');
    $article_data[] = $article['total_consomme'];
    // Générer une couleur aléatoire
    $article_colors[] = 'rgba(' . rand(0, 200) . ',' . rand(0, 200) . ',' . rand(200, 255) . ', 0.7)';
}

$category_labels = [];
$category_data = [];
$category_colors = [
    'rgba(54, 162, 235, 0.7)',
    'rgba(255, 99, 132, 0.7)',
    'rgba(255, 206, 86, 0.7)',
    'rgba(75, 192, 192, 0.7)',
    'rgba(153, 102, 255, 0.7)',
    'rgba(255, 159, 64, 0.7)'
];

foreach ($top_categories as $index => $category) {
    $category_labels[] = $category['categorie_nom'];
    $category_data[] = $category['total_consomme'];
}

$monthly_revenue_labels = [];
$monthly_revenue_data = [];

foreach ($monthly_revenue as $month) {
    $date = DateTime::createFromFormat('Y-n', $month['Année'] . '-' . $month['Mois']);
    $monthly_revenue_labels[] = $date->format('M Y');
    $monthly_revenue_data[] = $month['Total_Mensuel'];
}


// Convertir les données en format JSON pour les utiliser dans JavaScript
$article_labels_json = json_encode($article_labels);
$article_data_json = json_encode($article_data);
$article_colors_json = json_encode($article_colors);

$category_labels_json = json_encode($category_labels);
$category_data_json = json_encode($category_data);
$category_colors_json = json_encode($category_colors);

$monthly_revenue_labels_json = json_encode($monthly_revenue_labels);
$monthly_revenue_data_json = json_encode($monthly_revenue_data);
?>

<div class="flex h-screen bg-gray-100">
    <!-- Sidebar -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 overflow-y-auto">
        <!-- Top header -->
        <div class="bg-white shadow-sm">
            <div class="container mx-auto px-6 py-4 flex justify-between items-center">
                <h1 class="text-2xl font-semibold text-gray-800">Tableau de bord</h1>
                <div class="flex items-center space-x-4">
                    <!-- Notification Bell -->
                    <div class="relative">
                        <button id="notificationButton" class="relative p-2 text-gray-600 hover:text-gray-900 focus:outline-none">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
                            </svg>
                            <?php if ($total_alerts > 0): ?>
                                <span class="absolute top-0 right-0 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-red-100 transform translate-x-1/2 -translate-y-1/2 bg-red-600 rounded-full"><?php echo $total_alerts; ?></span>
                            <?php endif; ?>
                        </button>
                        
                        <!-- Popup de notifications -->
                        <div id="notificationPopup" class="hidden absolute right-0 mt-2 w-80 sm:w-96 bg-white rounded-lg shadow-lg z-50 overflow-hidden">
                            <!-- Tabs -->
                            <div class="flex border-b">
                                <button id="tab-all" class="flex-1 py-3 px-4 text-sm font-medium text-center text-blue-600 border-b-2 border-blue-600 focus:outline-none">
                                    Toutes (<?php echo $total_alerts; ?>)
                                </button>
                                <button id="tab-interventions" class="flex-1 py-3 px-4 text-sm font-medium text-center text-gray-500 hover:text-gray-700 focus:outline-none">
                                    Interventions (<?php echo count($interventions_alerts); ?>)
                                </button>
                                <button id="tab-factures" class="flex-1 py-3 px-4 text-sm font-medium text-center text-gray-500 hover:text-gray-700 focus:outline-none">
                                    Factures (<?php echo count($factures_alerts); ?>)
                                </button>
                            </div>
                            
                            <!-- Contenu des tabs -->
                            <div class="max-h-96 overflow-y-auto">
                                <!-- Tab All -->
                                <div id="content-all" class="p-3 space-y-3">
                                    <?php if ($total_alerts === 0): ?>
                                        <p class="text-center text-gray-500 py-4">Aucune notification</p>
                                    <?php else: ?>
                                        <!-- Interventions -->
                                        <?php foreach ($interventions_alerts as $alert): ?>
                                            <div class="flex items-center p-3 bg-yellow-50 border-l-4 border-yellow-400 rounded-lg">
                                                <div class="mr-3 text-yellow-500">
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                    </svg>
                                                </div>
                                                <div class="flex-1">
                                                    <p class="text-sm font-medium text-gray-800">
                                                        Intervention #<?php echo $alert['id']; ?>
                                                    </p>
                                                    <p class="text-xs text-gray-600">
                                                        Échéance: <?php echo date('d/m/Y', strtotime($alert['date_fin'])); ?>
                                                    </p>
                                                </div>
                                                <a href="interventions/edit.php?id=<?php echo $alert['id']; ?>" class="text-xs text-blue-600 hover:text-blue-800">
                                                    Voir
                                                </a>
                                            </div>
                                        <?php endforeach; ?>
                                        
                                        <!-- Factures -->
                                        <?php foreach ($factures_alerts as $alert): ?>
                                            <div class="flex items-center p-3 bg-red-50 border-l-4 border-red-400 rounded-lg">
                                                <div class="mr-3 text-red-500">
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                                    </svg>
                                                </div>
                                                <div class="flex-1">
                                                    <p class="text-sm font-medium text-gray-800">
                                                        Facture <?php echo htmlspecialchars($alert['Numero_Facture']); ?>
                                                    </p>
                                                    <p class="text-xs text-gray-600">
                                                        Date: <?php echo date('d/m/Y', strtotime($alert['Date_Facture'])); ?>
                                                    </p>
                                                </div>
                                                <a href="invoices/view_facture.php?id=<?php echo $alert['id']; ?>" class="text-xs text-blue-600 hover:text-blue-800">
                                                    Voir
                                                </a>
                                            </div>
                                        <?php endforeach; ?>
                                        
                                        <!-- Offres -->
                                        <?php foreach ($offres_alerts as $alert): ?>
                                            <div class="flex items-center p-3 bg-blue-50 border-l-4 border-blue-400 rounded-lg">
                                                <div class="mr-3 text-blue-500">
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                                                    </svg>
                                                </div>
                                                <div class="flex-1">
                                                    <p class="text-sm font-medium text-gray-800">
                                                        Offre <?php echo htmlspecialchars($alert['code']); ?>
                                                    </p>
                                                    <p class="text-xs text-gray-600">
                                                        Expire le: <?php echo date('d/m/Y', strtotime($alert['date_fin'])); ?>
                                                    </p>
                                                </div>
                                                <a href="offres/edit.php?id=<?php echo $alert['id']; ?>" class="text-xs text-blue-600 hover:text-blue-800">
                                                    Voir
                                                </a>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Tab Interventions -->
                                <div id="content-interventions" class="hidden p-3 space-y-3">
                                    <?php if (count($interventions_alerts) === 0): ?>
                                        <p class="text-center text-gray-500 py-4">Aucune intervention à signaler</p>
                                    <?php else: ?>
                                        <?php foreach ($interventions_alerts as $alert): ?>
                                            <div class="flex items-center p-3 bg-yellow-50 border-l-4 border-yellow-400 rounded-lg">
                                                <div class="mr-3 text-yellow-500">
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                                    </svg>
                                                </div>
                                                <div class="flex-1">
                                                    <p class="text-sm font-medium text-gray-800">
                                                        Intervention #<?php echo $alert['id']; ?> - <?php echo htmlspecialchars($alert['description']); ?>
                                                    </p>
                                                    <p class="text-xs text-gray-600">
                                                        Client: <?php echo htmlspecialchars($alert['nom'] . ' ' . $alert['prenom']); ?><br>
                                                        Véhicule: <?php echo htmlspecialchars($alert['immatriculation']); ?><br>
                                                        Échéance: <?php echo date('d/m/Y', strtotime($alert['date_fin'])); ?>
                                                    </p>
                                                </div>
                                                <a href="interventions/edit.php?id=<?php echo $alert['id']; ?>" class="text-xs text-blue-600 hover:text-blue-800">
                                                    Voir
                                                </a>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Tab Factures -->
                                <div id="content-factures" class="hidden p-3 space-y-3">
                                    <?php if (count($factures_alerts) === 0): ?>
                                        <p class="text-center text-gray-500 py-4">Aucune facture en attente</p>
                                    <?php else: ?>
                                        <?php foreach ($factures_alerts as $alert): ?>
                                            <div class="flex items-center p-3 bg-red-50 border-l-4 border-red-400 rounded-lg">
                                                <div class="mr-3 text-red-500">
                                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                                    </svg>
                                                </div>
                                                <div class="flex-1">
                                                    <p class="text-sm font-medium text-gray-800">
                                                        Facture <?php echo htmlspecialchars($alert['Numero_Facture']); ?>
                                                    </p>
                                                    <p class="text-xs text-gray-600">
                                                        Client: <?php echo htmlspecialchars($alert['nom'] . ' ' . $alert['prenom']); ?><br>
                                                        Date: <?php echo date('d/m/Y', strtotime($alert['Date_Facture'])); ?><br>
                                                        Montant: <?php echo number_format($alert['Montant_Total_HT'], 2, ',', ' '); ?> DH
                                                    </p>
                                                </div>
                                                <a href="invoices/view_facture.php?id=<?php echo $alert['id']; ?>" class="text-xs text-blue-600 hover:text-blue-800">
                                                    Voir
                                                </a>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <!-- Footer -->
                            <div class="border-t p-3 text-center">
                                <a href="#" class="text-xs text-blue-600 hover:text-blue-800 font-medium">
                                    Voir toutes les notifications
                                </a>
                            </div>
                        </div>
                    </div>
                    <div class="relative">
                        <span class="text-gray-700"><?php echo isset($currentUser['name']) ? htmlspecialchars($currentUser['name']) : 'Utilisateur'; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dashboard Content -->
        <div class="container mx-auto px-6 py-8">
            <!-- Section de bienvenue -->
            <div class="bg-gradient-to-r from-blue-600 to-indigo-700 rounded-xl shadow-xl mb-8 overflow-hidden">
                <div class="md:flex">
                    <div class="p-8 md:w-2/3">
                        <h2 class="text-3xl font-bold text-white mb-2">Bienvenue, <?php echo isset($currentUser['name']) ? htmlspecialchars($currentUser['name']) : 'Utilisateur'; ?> !</h2>
                        <p class="text-blue-100 text-lg mb-6">Votre tableau de bord de gestion automobile est prêt.</p>
                        <div class="flex flex-wrap gap-4">
                            <a href="clients/create.php" class="bg-white text-blue-700 hover:bg-blue-50 font-medium py-2 px-4 rounded-lg transition duration-300 flex items-center">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                                </svg>
                                Nouveau client
                            </a>
                            <a href="interventions/index.php" class="bg-blue-800 text-white hover:bg-blue-900 font-medium py-2 px-4 rounded-lg transition duration-300 flex items-center">
                                <svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                                Interventions
                            </a>
                        </div>
                    </div>
                    <div class="hidden md:block md:w-1/3">
                        <div class="h-full flex items-center justify-center p-6">
                            <div class="bg-white bg-opacity-10 p-6 rounded-full">
                                <svg class="w-32 h-32 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                                </svg>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Résumé des activités récentes -->
            <div class="bg-white rounded-xl shadow-md p-6 mb-8">
                <h3 class="text-xl font-semibold text-gray-800 mb-4">Aperçu de l'activité</h3>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div class="bg-blue-50 p-4 rounded-lg border border-blue-100">
                        <div class="flex items-center">
                            <div class="bg-blue-100 p-3 rounded-full mr-4">
                                <svg class="w-6 h-6 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm text-blue-600 font-semibold">Interventions en cours</p>
                                <p class="text-2xl font-bold text-gray-800"><?php echo count($interventions_alerts); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-red-50 p-4 rounded-lg border border-red-100">
                        <div class="flex items-center">
                            <div class="bg-red-100 p-3 rounded-full mr-4">
                                <svg class="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm text-red-600 font-semibold">Factures en attente</p>
                                <p class="text-2xl font-bold text-gray-800"><?php echo count($factures_alerts); ?></p>
                            </div>
                        </div>
                    </div>
                    
                    <div class="bg-green-50 p-4 rounded-lg border border-green-100">
                        <div class="flex items-center">
                            <div class="bg-green-100 p-3 rounded-full mr-4">
                                <svg class="w-6 h-6 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm text-green-600 font-semibold">Clients actifs</p>
                                <p class="text-2xl font-bold text-gray-800"><?php echo $total_clients; ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Statistiques de consommation d'articles -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
                <!-- Articles les plus consommés -->
                <div class="bg-white rounded-xl shadow-md p-6 lg:col-span-2">
                    <h3 class="text-xl font-semibold text-gray-800 mb-4">Articles les plus consommés</h3>
                    <div style="height: 300px;">
                        <canvas id="topArticlesChart"></canvas>
                    </div>
                </div>
                
                <!-- Consommation par catégorie -->
                <div class="bg-white rounded-xl shadow-md p-6">
                    <h3 class="text-xl font-semibold text-gray-800 mb-4">Consommation par catégorie</h3>
                    <div style="height: 300px;">
                        <canvas id="categoriesChart"></canvas>
                    </div>
                </div>
            </div>

           <!-- Évolution du chiffre d'affaire -->
<div class="bg-white rounded-xl shadow-md p-6 mb-8">
    <h3 class="text-xl font-semibold text-gray-800 mb-4">Évolution du chiffre d'affaire</h3>
    <div style="height: 300px;">
        <canvas id="monthlyRevenueChart"></canvas>
    </div>
</div>

            <!-- Calendrier des interventions -->
            <div class="bg-white rounded-xl shadow-md p-6 mb-8">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-semibold text-gray-800">Calendrier des interventions</h3>
                    <div class="flex gap-2">
                        <button id="prev-month-btn" class="px-3 py-1 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                            </svg>
                        </button>
                        <div id="month-year-display" class="px-3 py-1 font-medium"></div>
                        <button id="next-month-btn" class="px-3 py-1 bg-gray-200 text-gray-700 rounded-md hover:bg-gray-300">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                            </svg>
                        </button>
                    </div>
                </div>
                
                <div class="calendar-container">
                    <div class="weekdays grid grid-cols-7 text-center font-medium bg-gray-100 py-2">
                        <div>Dim</div>
                        <div>Lun</div>
                        <div>Mar</div>
                        <div>Mer</div>
                        <div>Jeu</div>
                        <div>Ven</div>
                        <div>Sam</div>
                    </div>
                    
                    <div id="calendar-grid" class="grid grid-cols-7 grid-rows-6 gap-1 bg-gray-200 p-1">
                        <!-- Les jours du calendrier seront générés par JavaScript -->
                    </div>
                </div>
                
                <div class="mt-4 flex justify-end">
                    <a href="interventions/index.php?view=calendar" class="text-blue-600 hover:text-blue-800 text-sm font-medium flex items-center">
                        Voir toutes les interventions
                        <svg class="w-4 h-4 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                        </svg>
                    </a>
                </div>
            </div>

            
            <!-- Main Actions Grid -->
            <h3 class="text-xl font-semibold text-gray-800 mb-4">Accès rapide</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">

                <!-- Clients Management -->
                <a href="clients/" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition duration-300 flex flex-col items-center justify-center text-center">
                    <div class="bg-blue-100 p-4 rounded-full mb-4">
                        <svg class="w-8 h-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                        </svg>
                    </div>
                    <h3 class="text-lg font-medium text-gray-800">Clients</h3>
                    <p class="text-sm text-gray-600 mt-2">Gérer les informations clients</p>
                </a>

                <!-- Vehicles Management -->
                <a href="vehicles/view.php" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition duration-300 flex flex-col items-center justify-center text-center">
                    <div class="bg-green-100 p-4 rounded-full mb-4">
                        <svg class="w-8 h-8 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                        </svg>
                    </div>
                    <h3 class="text-lg font-medium text-gray-800">Véhicules</h3>
                    <p class="text-sm text-gray-600 mt-2">Gérer le parc automobile</p>
                </a>

                <!-- Interventions Management -->
                <a href="interventions/" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition duration-300 flex flex-col items-center justify-center text-center">
                    <div class="bg-yellow-100 p-4 rounded-full mb-4">
                        <svg class="w-8 h-8 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z"></path>
                        </svg>
                    </div>
                    <h3 class="text-lg font-medium text-gray-800">Interventions</h3>
                    <p class="text-sm text-gray-600 mt-2">Gérer les réparations et services</p>
                </a>

                <!-- Stock Management -->
                <a href="stock/" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition duration-300 flex flex-col items-center justify-center text-center">
                    <div class="bg-indigo-100 p-4 rounded-full mb-4">
                        <svg class="w-8 h-8 text-indigo-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                        </svg>
                    </div>
                    <h3 class="text-lg font-medium text-gray-800">Stock</h3>
                    <p class="text-sm text-gray-600 mt-2">Gérer l'inventaire des pièces</p>
                </a>

                <!-- Invoices Management -->
                <a href="invoices/" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition duration-300 flex flex-col items-center justify-center text-center">
                    <div class="bg-purple-100 p-4 rounded-full mb-4">
                        <svg class="w-8 h-8 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                        </svg>
                    </div>
                    <h3 class="text-lg font-medium text-gray-800">Factures</h3>
                    <p class="text-sm text-gray-600 mt-2">Gérer la facturation</p>
                </a>

                <!-- Orders Management -->
                <a href="orders/" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition duration-300 flex flex-col items-center justify-center text-center">
                    <div class="bg-red-100 p-4 rounded-full mb-4">
                        <svg class="w-8 h-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"></path>
                        </svg>
                    </div>
                    <h3 class="text-lg font-medium text-gray-800">Commandes</h3>
                    <p class="text-sm text-gray-600 mt-2">Gérer les bons de commande</p>
                </a>

                <!-- Deliveries Management -->
                <a href="offres/" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition duration-300 flex flex-col items-center justify-center text-center">
                    <div class="bg-pink-100 p-4 rounded-full mb-4">
                    <svg class="w-8 h-8 text-pink-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7a1 1 0 011-1h4.586a1 1 0 01.707.293l7.414 7.414a1 1 0 010 1.414l-4.586 4.586a1 1 0 01-1.414 0L4.293 9.707A1 1 0 014 9V8a1 1 0 011-1h2z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01" />
                        </svg>  
                    
                    </div>
                    <h3 class="text-lg font-medium text-gray-800">Offres</h3>
                    <p class="text-sm text-gray-600 mt-2">Gérer les offres</p>
                </a>

                <!-- Users Management -->
                <a href="users/" class="bg-white rounded-lg shadow-md p-6 hover:shadow-lg transition duration-300 flex flex-col items-center justify-center text-center">
                    <div class="bg-gray-100 p-4 rounded-full mb-4">
                        <svg class="w-8 h-8 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                        </svg>
                    </div>
                    <h3 class="text-lg font-medium text-gray-800">Utilisateurs</h3>
                    <p class="text-sm text-gray-600 mt-2">Gérer les comptes utilisateurs</p>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Ajouter les styles CSS pour le calendrier -->
<style>
    .calendar-container {
        border: 1px solid #e5e7eb;
        border-radius: 0.5rem;
        overflow: hidden;
    }
    
    .day-cell {
        border: 1px solid #e5e7eb;
        min-height: 100px;
        position: relative;
    }
    
    .day-cell:hover {
        background-color: #f9fafb;
    }
    
    .interventions-container {
        margin-top: 4px;
    }
</style>

<!-- Ajoutez ce script JavaScript avant la fin de votre fichier -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const notificationButton = document.getElementById('notificationButton');
    const notificationPopup = document.getElementById('notificationPopup');

    // Tabs
    const tabAll = document.getElementById('tab-all');
    const tabInterventions = document.getElementById('tab-interventions');
    const tabFactures = document.getElementById('tab-factures');

    // Contents
    const contentAll = document.getElementById('content-all');
    const contentInterventions = document.getElementById('content-interventions');
    const contentFactures = document.getElementById('content-factures');

    // Fonction pour afficher/masquer la popup
    notificationButton.addEventListener('click', function(event) {
        event.stopPropagation();
        notificationPopup.classList.toggle('hidden');
    });

    // Fermer la popup si on clique ailleurs
    document.addEventListener('click', function(event) {
        if (!notificationPopup.contains(event.target) && event.target !== notificationButton) {
            notificationPopup.classList.add('hidden');
        }
    });

    // Empêcher la propagation des clics dans la popup
    notificationPopup.addEventListener('click', function(event) {
        event.stopPropagation();
    });

    // Gestion des tabs
    tabAll.addEventListener('click', function() {
        // Activer ce tab
        tabAll.classList.add('text-blue-600', 'border-b-2', 'border-blue-600');
        tabInterventions.classList.remove('text-blue-600', 'border-b-2', 'border-blue-600');
        tabFactures.classList.remove('text-blue-600', 'border-b-2', 'border-blue-600');

        tabInterventions.classList.add('text-gray-500');
        tabFactures.classList.add('text-gray-500');

        // Afficher le contenu correspondant
        contentAll.classList.remove('hidden');
        contentInterventions.classList.add('hidden');
        contentFactures.classList.add('hidden');
    });

    tabInterventions.addEventListener('click', function() {
        // Activer ce tab
        tabAll.classList.remove('text-blue-600', 'border-b-2', 'border-blue-600');
        tabInterventions.classList.add('text-blue-600', 'border-b-2', 'border-blue-600');
        tabFactures.classList.remove('text-blue-600', 'border-b-2', 'border-blue-600');

        tabAll.classList.add('text-gray-500');
        tabFactures.classList.add('text-gray-500');

        // Afficher le contenu correspondant
        contentAll.classList.add('hidden');
        contentInterventions.classList.remove('hidden');
        contentFactures.classList.add('hidden');
    });

    tabFactures.addEventListener('click', function() {
        // Activer ce tab
        tabAll.classList.remove('text-blue-600', 'border-b-2', 'border-blue-600');
        tabInterventions.classList.remove('text-blue-600', 'border-b-2', 'border-blue-600');
        tabFactures.classList.add('text-blue-600', 'border-b-2', 'border-blue-600');

        tabAll.classList.add('text-gray-500');
        tabInterventions.classList.add('text-gray-500');

        // Afficher le contenu correspondant
        contentAll.classList.add('hidden');
        contentInterventions.classList.add('hidden');
        contentFactures.classList.remove('hidden');
    });
    
    // Récupérer les interventions pour le calendrier
    let calendarInterventions = <?php echo $calendarInterventions; ?>;

    // Initialisation du calendrier
    let currentCalendarDate = new Date();
    
    // Générer le calendrier initial
    generateCalendar(currentCalendarDate.getFullYear(), currentCalendarDate.getMonth());

    // Gérer la navigation entre les mois
    document.getElementById('prev-month-btn').addEventListener('click', () => {
        currentCalendarDate.setMonth(currentCalendarDate.getMonth() - 1);
        generateCalendar(currentCalendarDate.getFullYear(), currentCalendarDate.getMonth());
    });

    document.getElementById('next-month-btn').addEventListener('click', () => {
        currentCalendarDate.setMonth(currentCalendarDate.getMonth() + 1);
        generateCalendar(currentCalendarDate.getFullYear(), currentCalendarDate.getMonth());
    });
    
    // Fonction pour générer le calendrier
    function generateCalendar(year, month) {
        const calendarGrid = document.getElementById('calendar-grid');
        if (!calendarGrid) return; // Sortir si l'élément n'existe pas
        
        const monthYearDisplay = document.getElementById('month-year-display');
        
        // Vider le calendrier
        calendarGrid.innerHTML = '';
        
        // Mettre à jour l'affichage du mois et de l'année
        const monthNames = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 
                            'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
        monthYearDisplay.textContent = `${monthNames[month]} ${year}`;
        
        // Obtenir le premier jour du mois (0 = Dimanche, 1 = Lundi, etc.)
        const firstDay = new Date(year, month, 1).getDay();
        
        // Obtenir le nombre de jours dans le mois
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        
        // Obtenir le dernier jour du mois précédent
        const daysInPrevMonth = new Date(year, month, 0).getDate();
        
        // Date actuelle pour surligner le jour courant
        const today = new Date();
        const currentDate = today.getDate();
        const currentMonth = today.getMonth();
        const currentYear = today.getFullYear();
        
        // Générer les jours du mois précédent (grisés)
        for (let i = 0; i < firstDay; i++) {
            const day = daysInPrevMonth - firstDay + i + 1;
            const prevMonth = month - 1 < 0 ? 11 : month - 1;
            const prevYear = prevMonth === 11 ? year - 1 : year;
            const dateString = `${prevYear}-${String(prevMonth + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            
            const dayCell = document.createElement('div');
            dayCell.className = 'day-cell bg-gray-100 p-1 h-24 overflow-y-auto';
            dayCell.innerHTML = `
                <div class="text-gray-400 text-sm font-medium">${day}</div>
                <div class="interventions-container"></div>
            `;
            
            // Ajouter les interventions pour ce jour (s'il y en a)
            addInterventionsToCell(dayCell, dateString);
            
            calendarGrid.appendChild(dayCell);
        }
        
        // Générer les jours du mois courant
        for (let day = 1; day <= daysInMonth; day++) {
            const dateString = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            
            const dayCell = document.createElement('div');
            dayCell.className = 'day-cell bg-white p-1 h-24 overflow-y-auto';
            
            // Surligner le jour courant
            if (day === currentDate && month === currentMonth && year === currentYear) {
                dayCell.classList.add('bg-blue-50', 'border', 'border-blue-300');
            }
            
            dayCell.innerHTML = `
                <div class="text-gray-800 text-sm font-medium">${day}</div>
                <div class="interventions-container"></div>
            `;
            
            // Ajouter les interventions pour ce jour (s'il y en a)
            addInterventionsToCell(dayCell, dateString);
            
            // Ajouter un événement pour ajouter une intervention à cette date
            dayCell.addEventListener('dblclick', () => {
                window.location.href = `interventions/index.php?view=calendar&date=${dateString}`;
            });
            
            calendarGrid.appendChild(dayCell);
        }
        
        // Calculer le nombre de cellules nécessaires pour compléter la grille (6 lignes de 7 jours)
        const remainingCells = 42 - (firstDay + daysInMonth);
        
        // Générer les jours du mois suivant (grisés)
        for (let day = 1; day <= remainingCells; day++) {
            const nextMonth = month + 1 > 11 ? 0 : month + 1;
            const nextYear = nextMonth === 0 ? year + 1 : year;
            const dateString = `${nextYear}-${String(nextMonth + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            
            const dayCell = document.createElement('div');
            dayCell.className = 'day-cell bg-gray-100 p-1 h-24 overflow-y-auto';
            dayCell.innerHTML = `
                <div class="text-gray-400 text-sm font-medium">${day}</div>
                <div class="interventions-container"></div>
            `;
            
            // Ajouter les interventions pour ce jour (s'il y en a)
            addInterventionsToCell(dayCell, dateString);
            
            calendarGrid.appendChild(dayCell);
        }
    }

    // Fonction pour ajouter les interventions à une cellule du calendrier
    function addInterventionsToCell(cell, dateString) {
        const interventionsContainer = cell.querySelector('.interventions-container');
        
        if (calendarInterventions[dateString]) {
            const interventions = calendarInterventions[dateString];
            
            interventions.forEach(intervention => {
                const statusClass = getStatusClass(intervention.statut);
                
                const interventionElement = document.createElement('div');
                interventionElement.className = `text-xs p-1 mb-1 rounded ${statusClass} cursor-pointer hover:opacity-80`;
                interventionElement.innerHTML = `
                    <div class="font-medium">#${intervention.id} - ${intervention.client}</div>
                    <div class="text-xs truncate">${intervention.vehicule}</div>
                `;
                
                // Ajouter un événement pour voir les détails de l'intervention
                interventionElement.addEventListener('click', () => {
                    window.location.href = `interventions/index.php?id=${intervention.id}`;
                });
                
                interventionsContainer.appendChild(interventionElement);
            });
        }
    }

    // Fonction pour obtenir la classe CSS en fonction du statut
    function getStatusClass(statut) {
        switch (statut) {
            case 'En attente':
                return 'bg-yellow-100 text-yellow-800 border-l-2 border-yellow-500';
            case 'En cours':
                return 'bg-blue-100 text-blue-800 border-l-2 border-blue-500';
            case 'Terminée':
                return 'bg-green-100 text-green-800 border-l-2 border-green-500';
            case 'Facturée':
                return 'bg-purple-100 text-purple-800 border-l-2 border-purple-500';
            case 'Annulée':
                return 'bg-red-100 text-red-800 border-l-2 border-red-500';
            default:
                return 'bg-gray-100 text-gray-800 border-l-2 border-gray-500';
        }
    }

    // Initialisation des graphiques avec Chart.js
    // Vérifier si Chart.js est chargé
    if (typeof Chart !== 'undefined') {
        // Articles les plus consommés
        const topArticlesCtx = document.getElementById('topArticlesChart').getContext('2d');
        const topArticlesChart = new Chart(topArticlesCtx, {
            type: 'bar',
            data: {
                labels: <?php echo $article_labels_json; ?>,
                datasets: [{
                    label: 'Quantité consommée',
                    data: <?php echo $article_data_json; ?>,
                    backgroundColor: <?php echo $article_colors_json; ?>,
                    borderColor: <?php echo $article_colors_json; ?>,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Quantité'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Articles'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            title: function(tooltipItems) {
                                return tooltipItems[0].label;
                            }
                        }
                    }
                }
            }
        });

        // Consommation par catégorie
        const categoriesCtx = document.getElementById('categoriesChart').getContext('2d');
        const categoriesChart = new Chart(categoriesCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo $category_labels_json; ?>,
                datasets: [{
                    data: <?php echo $category_data_json; ?>,
                    backgroundColor: <?php echo $category_colors_json; ?>,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            boxWidth: 15,
                            font: {
                                size: 12
                            }
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.raw || 0;
                                const total = context.chart.data.datasets[0].data.reduce((a, b) => a + b, 0);
                                const percentage = Math.round((value / total) * 100);
                                return `${label}: ${value} (${percentage}%)`;
                            }
                        }
                    }
                }
            }
        });
// Évolution mensuelle du chiffre d'affaire
const monthlyRevenueCtx = document.getElementById('monthlyRevenueChart').getContext('2d');
const monthlyRevenueChart = new Chart(monthlyRevenueCtx, {
    type: 'line',
    data: {
        labels: <?php echo $monthly_revenue_labels_json; ?>,
        datasets: [{
            label: 'Chiffre d\'affaire mensuel',
            data: <?php echo $monthly_revenue_data_json; ?>,
            backgroundColor: 'rgba(75, 192, 192, 0.2)',
            borderColor: 'rgba(75, 192, 192, 1)',
            borderWidth: 2,
            tension: 0.3,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true,
                title: {
                    display: true,
                    text: 'Montant (DH)'
                },
                ticks: {
                    callback: function(value) {
                        return value.toLocaleString('fr-MA') + ' DH';
                    }
                }
            },
            x: {
                title: {
                    display: true,
                    text: 'Mois'
                }
            }
        },
        plugins: {
            legend: {
                display: false
            },
            tooltip: {
                callbacks: {
                    label: function(context) {
                        let label = context.dataset.label || '';
                        if (label) {
                            label += ': ';
                        }
                        if (context.parsed.y !== null) {
                            label += context.parsed.y.toLocaleString('fr-MA') + ' DH';
                        }
                        return label;
                    }
                }
            }
        }
    }
});
    } else {
        console.warn('Chart.js n\'est pas chargé. Les graphiques ne seront pas affichés.');
        
        // Afficher un message dans les conteneurs de graphiques
        const chartContainers = document.querySelectorAll('[id$="Chart"]');
        chartContainers.forEach(container => {
            const parent = container.parentElement;
            parent.innerHTML = `
                <div class="flex items-center justify-center h-full">
                    <div class="text-center text-gray-500">
                        <svg class="mx-auto h-12 w-12" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                        <p class="mt-2">Impossible de charger le graphique</p>
                        <p class="text-sm">Chart.js n'est pas disponible</p>
                    </div>
                </div>
            `;
        });
    }
});
</script>

<!-- Inclure Chart.js pour les graphiques -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<?php include 'includes/footer.php'; ?>
