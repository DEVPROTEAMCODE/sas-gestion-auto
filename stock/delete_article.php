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
$currentUser = [
    'name' => 'Utilisateur Test',
    'role' => 'Administrateur'
];

// Initialiser les variables
$message = '';
$status = '';
$article = null;

// Vérifier si l'ID de l'article est fourni
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$article_id = intval($_GET['id']);

// Connexion à la base de données
$database = new Database();
$db = $database->getConnection();

// Récupérer les informations de l'article avant suppression
try {
    $query = "SELECT * FROM articles WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $article_id);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        // L'article n'existe pas
        $message = "L'article demandé n'existe pas.";
        $status = 'error';
    } else {
        $article = $stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $message = "Erreur lors de la récupération des informations de l'article: " . $e->getMessage();
    $status = 'error';
}

// Traitement de la suppression
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_delete']) && $_POST['confirm_delete'] === 'yes') {
    try {
        // Vérifier si l'article est utilisé dans des commandes ou d'autres tables
        $check_query = "SELECT COUNT(*) as count FROM lignes_commande WHERE ID_Article = :id";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':id', $article_id);
        $check_stmt->execute();
        $result = $check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] > 0) {
            // L'article est utilisé dans des commandes, ne pas supprimer
            $message = "Impossible de supprimer cet article car il est utilisé dans " . $result['count'] . " commande(s).";
            $status = 'error';
        } else {
            // Supprimer l'article
            $query = "DELETE FROM article WHERE id = :id";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $article_id);
            
            if ($stmt->execute()) {
                $message = "L'article a été supprimé avec succès.";
                $status = 'success';
                
                // Rediriger vers la liste des articles après 2 secondes
                header("refresh:2;url=index.php");
            } else {
                $message = "Erreur lors de la suppression de l'article.";
                $status = 'error';
            }
        }
    } catch (PDOException $e) {
        $message = "Erreur de base de données: " . $e->getMessage();
        $status = 'error';
    }
}

// Inclure l'en-tête
include $root_path . '/includes/header.php';
?>

<div class="flex h-screen bg-gray-100">
    <!-- Sidebar -->
    <?php include $root_path . '/includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 overflow-auto p-6">
        <div class="bg-white rounded-lg shadow-md p-6">
            <h1 class="text-2xl font-bold text-gray-800 mb-6">Suppression d'un Article</h1>
            
            <?php if (!empty($message)): ?>
                <div class="mb-6 px-4 py-3 rounded <?php echo $status === 'success' ? 'bg-green-100 text-green-700 border border-green-400' : 'bg-red-100 text-red-700 border border-red-400'; ?>">
                    <?php echo $message; ?>
                </div>
                
                <?php if ($status === 'success'): ?>
                    <div class="mb-6">
                        <p class="text-gray-600">Vous allez être redirigé vers la liste des articles...</p>
                        <div class="mt-4">
                            <a href="index.php" class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 active:bg-blue-900 focus:outline-none focus:border-blue-900 focus:ring ring-blue-300 disabled:opacity-25 transition ease-in-out duration-150">
                                Retour à la liste
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php if ($article && $status !== 'success'): ?>
                <div class="mb-6 bg-yellow-50 border-l-4 border-yellow-400 p-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <svg class="h-5 w-5 text-yellow-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                            </svg>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-yellow-700">
                                Attention! Vous êtes sur le point de supprimer définitivement cet article. Cette action est irréversible.
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="bg-gray-50 p-4 rounded-md mb-6">
                    <h2 class="text-lg font-medium text-gray-900 mb-4">Détails de l'article à supprimer</h2>
                    <dl class="grid grid-cols-1 gap-x-4 gap-y-6 sm:grid-cols-2">
                        <div>
                            <dt class="text-sm font-medium text-gray-500">ID</dt>
                            <dd class="mt-1 text-sm text-gray-900"><?php echo $article['id']; ?></dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Référence</dt>
                            <dd class="mt-1 text-sm text-gray-900"><?php echo htmlspecialchars($article['reference']); ?></dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Désignation</dt>
                            <dd class="mt-1 text-sm text-gray-900"><?php echo htmlspecialchars($article['designation']); ?></dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Prix d'achat</dt>
                            <dd class="mt-1 text-sm text-gray-900"><?php echo number_format($article['prix_achat'], 2, ',', ' '); ?> DH</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Prix de vente HT</dt>
                            <dd class="mt-1 text-sm text-gray-900"><?php echo number_format($article['prix_vente_ht'], 2, ',', ' '); ?> DH</dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-gray-500">Quantité en stock</dt>
                            <dd class="mt-1 text-sm text-gray-900"><?php echo $article['quantite_stock']; ?></dd>
                        </div>
                    </dl>
                </div>
                
                <form method="POST" action="" id="delete-form">
                    <input type="hidden" name="confirm_delete" value="yes">
                    
                    <div class="flex items-center mb-6">
                        <input id="confirm-checkbox" type="checkbox" class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded" required>
                        <label for="confirm-checkbox" class="ml-2 block text-sm text-gray-900">
                            Je confirme vouloir supprimer définitivement cet article
                        </label>
                    </div>
                    
                    <div class="flex justify-end space-x-4">
                        <a href="index.php" class="px-4 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500">
                            Annuler
                        </a>
                        <button type="submit" id="delete-btn" class="px-4 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500" disabled>
                            Supprimer définitivement
                        </button>
                    </div>
                </form>
            <?php elseif ($status !== 'success'): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    L'article demandé n'existe pas ou a déjà été supprimé.
                </div>
                <div class="mt-4">
                    <a href="index.php" class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 active:bg-blue-900 focus:outline-none focus:border-blue-900 focus:ring ring-blue-300 disabled:opacity-25 transition ease-in-out duration-150">
                        Retour à la liste des articles
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const confirmCheckbox = document.getElementById('confirm-checkbox');
        const deleteBtn = document.getElementById('delete-btn');
        
        if (confirmCheckbox && deleteBtn) {
            confirmCheckbox.addEventListener('change', function() {
                deleteBtn.disabled = !this.checked;
            });
        }
        
        const deleteForm = document.getElementById('delete-form');
        if (deleteForm) {
            deleteForm.addEventListener('submit', function(e) {
                if (!confirmCheckbox.checked) {
                    e.preventDefault();
                    alert('Veuillez confirmer la suppression en cochant la case.');
                    return false;
                }
                
                if (!confirm('Êtes-vous vraiment sûr de vouloir supprimer cet article ? Cette action est irréversible.')) {
                    e.preventDefault();
                    return false;
                }
                
                return true;
            });
        }
    });
</script>

<?php
// Inclure le pied de page
include $root_path . '/includes/footer.php';
?>
