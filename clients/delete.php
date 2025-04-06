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

// Vérifier si l'ID du client est fourni
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$client_id = intval($_GET['id']);
$error = null;
$client = null;
$confirmation = isset($_POST['confirm']) && $_POST['confirm'] === 'yes';

// Connexion à la base de données
$database = new Database();
$db = $database->getConnection();

// Récupérer les informations du client avant la suppression
try {
    $query = "SELECT id, nom, prenom FROM clients WHERE id = :id LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $client_id);
    $stmt->execute();
    
    if ($stmt->rowCount() > 0) {
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // Client non trouvé, rediriger vers la liste
        header('Location: index.php');
        exit;
    }
} catch (PDOException $e) {
    $error = 'Erreur lors de la récupération du client: ' . $e->getMessage();
}

// Vérifier s'il y a des véhicules associés à ce client
$hasVehicles = false;
try {
    $query = "SELECT COUNT(*) as count FROM vehicules WHERE client_id = :client_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':client_id', $client_id);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $hasVehicles = ($result['count'] > 0);
} catch (PDOException $e) {
    // Si la table n'existe pas ou autre erreur, on continue
}

// Vérifier s'il y a des interventions associées à ce client
$hasInterventions = false;
try {
    $query = "SELECT COUNT(*) as count FROM interventions WHERE client_id = :client_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':client_id', $client_id);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $hasInterventions = ($result['count'] > 0);
} catch (PDOException $e) {
    // Si la table n'existe pas ou autre erreur, on continue
}

// Traitement de la suppression
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $confirmation) {
    try {
        // Commencer une transaction
        $db->beginTransaction();
        
        // Si l'option de suppression en cascade est activée
        if (isset($_POST['delete_related']) && $_POST['delete_related'] === 'yes') {
            // Supprimer les interventions liées aux véhicules du client
            $query = "DELETE FROM interventions WHERE vehicule_id IN (SELECT id FROM vehicules WHERE client_id = :client_id)";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':client_id', $client_id);
            $stmt->execute();
            
            // Supprimer les véhicules du client
            $query = "DELETE FROM vehicules WHERE client_id = :client_id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':client_id', $client_id);
            $stmt->execute();
        } else if ($hasVehicles || $hasInterventions) {
            // Si le client a des données associées et qu'on ne veut pas les supprimer
            throw new Exception("Le client a des véhicules ou interventions associés. Veuillez les supprimer d'abord ou cocher l'option de suppression en cascade.");
        }
        
        // Supprimer le client
        $query = "DELETE FROM clients WHERE id = :id";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':id', $client_id);
        $stmt->execute();
        
        // Valider la transaction
        $db->commit();
        
        // Rediriger avec un message de succès
        $_SESSION['success_message'] = 'Le client a été supprimé avec succès.';
        header('Location: index.php');
        exit;
        
    } catch (Exception $e) {
        // Annuler la transaction en cas d'erreur
        $db->rollBack();
        $error = $e->getMessage();
    }
}

// Inclure l'en-tête
include $root_path . '/includes/header.php';

// Utilisateur temporaire pour éviter l'erreur
$currentUser = [
    'name' => 'Utilisateur Test',
    'role' => 'Administrateur'
];
?>

<div class="flex h-screen bg-gray-100">
    <!-- Sidebar -->
    <?php include $root_path . '/includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 overflow-y-auto">
        <!-- Top header -->
        <div class="bg-white shadow-sm">
            <div class="container mx-auto px-6 py-4 flex justify-between items-center">
                <h1 class="text-2xl font-semibold text-gray-800">Supprimer un client</h1>
                <div class="flex items-center space-x-4">
                    <div class="relative">
                        <span class="text-gray-700"><?php echo isset($currentUser['name']) ? htmlspecialchars($currentUser['name']) : 'Utilisateur'; ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Delete Client Content -->
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
                    <li class="text-gray-800 font-medium">Supprimer</li>
                </ol>
            </nav>
            
            <!-- Error Message -->
            <?php if ($error): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-6 rounded" role="alert">
                    <p class="font-bold">Erreur!</p>
                    <p><?php echo $error; ?></p>
                </div>
            <?php endif; ?>
            
            <!-- Delete Confirmation Card -->
            <div class="bg-white rounded-lg shadow-md overflow-hidden">
                <div class="p-6">
                    <div class="flex items-center mb-6">
                        <div class="h-12 w-12 rounded-full bg-red-100 flex items-center justify-center mr-4">
                            <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                            </svg>
                        </div>
                        <h2 class="text-xl font-semibold text-gray-800">Confirmation de suppression</h2>
                    </div>
                    
                    <p class="text-gray-700 mb-6">
                        Vous êtes sur le point de supprimer définitivement le client <strong><?php echo htmlspecialchars($client['prenom'] . ' ' . $client['nom']); ?></strong>.
                        Cette action est irréversible et toutes les données associées à ce client seront perdues.
                    </p>
                    
                    <?php if ($hasVehicles || $hasInterventions): ?>
                        <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-6">
                            <div class="flex">
                                <div class="flex-shrink-0">
                                    <svg class="h-5 w-5 text-yellow-400" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                                    </svg>
                                </div>
                                <div class="ml-3">
                                    <p class="text-sm text-yellow-700">
                                        <strong>Attention:</strong> Ce client a des données associées.
                                        <?php if ($hasVehicles): ?>
                                            <span class="font-medium">Véhicules associés.</span>
                                        <?php endif; ?>
                                        <?php if ($hasInterventions): ?>
                                            <span class="font-medium">Interventions associées.</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <form action="delete.php?id=<?php echo $client_id; ?>" method="POST">
                        <?php if ($hasVehicles || $hasInterventions): ?>
                            <div class="mb-6">
                                <label class="flex items-center">
                                    <input type="checkbox" name="delete_related" value="yes" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded">
                                    <span class="ml-2 text-gray-700">Supprimer également tous les véhicules et interventions associés à ce client</span>
                                </label>
                            </div>
                        <?php endif; ?>
                        
                        <div class="mb-6">
                            <label class="flex items-center">
                                <input type="checkbox" name="confirm" value="yes" class="h-4 w-4 text-indigo-600 focus:ring-indigo-500 border-gray-300 rounded" required>
                                <span class="ml-2 text-gray-700">Je confirme vouloir supprimer ce client</span>
                            </label>
                        </div>
                        
                        <!-- Form Actions -->
                        <div class="flex justify-end space-x-3">
                            <a href="index.php" class="px-6 py-2 border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition">
                                Annuler
                            </a>
                            <button type="submit" class="px-6 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition">
                                Supprimer définitivement
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Info Card -->
           
        </div>
    </div>
</div>

<?php include $root_path . '/includes/footer.php'; ?>
