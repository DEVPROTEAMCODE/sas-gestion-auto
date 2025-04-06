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

// Utilisateur temporaire pour éviter l'erreur
$currentUser = [
    'name' => 'Utilisateur Test',
    'role' => 'Administrateur'
];

// Récupérer les informations de la société
$database = new Database();
$db = $database->getConnection();

$company_info = null;
$success_message = '';
$error_message = '';

try {
    // Récupérer les informations de la société
    $query = "SELECT * FROM societe LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->execute();
    $company_info = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Erreur lors de la récupération des informations: " . $e->getMessage();
}

// Traitement du formulaire
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $raison_sociale = $_POST['raison_sociale'] ?? '';
        $patente = $_POST['patente'] ?? '';
        $date_creation = $_POST['date_creation'] ?? null;
        $gerant = $_POST['gerant'] ?? '';
        $adresse = $_POST['adresse'] ?? '';
        $telephone_fixe = $_POST['telephone_fixe'] ?? '';
        $telephone_mobile = $_POST['telephone_mobile'] ?? '';
        $email = $_POST['email'] ?? '';
        
        // Gérer le téléchargement du logo
        $logo_path = $company_info['logo'] ?? '';
        
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = $root_path . '/uploads/';
            
            // Créer le répertoire s'il n'existe pas
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file_extension = pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION);
            $new_filename = 'company_logo_' . time() . '.' . $file_extension;
            $upload_path = $upload_dir . $new_filename;
            
            // Vérifier le type de fichier
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
            if (!in_array($_FILES['logo']['type'], $allowed_types)) {
                throw new Exception("Type de fichier non autorisé. Seuls les formats JPEG, PNG et GIF sont acceptés.");
            }
            
            // Déplacer le fichier téléchargé
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $upload_path)) {
                // Supprimer l'ancien logo s'il existe
                if (!empty($logo_path) && file_exists($root_path . '/' . $logo_path)) {
                    unlink($root_path . '/' . $logo_path);
                }
                
                $logo_path = 'uploads/' . $new_filename;
                
                // Pour débogage
                error_log("Nouveau logo enregistré: " . $logo_path);
            } else {
                throw new Exception("Erreur lors du téléchargement du logo.");
            }
        }
        
        // Mettre à jour ou insérer les informations de la société
        if ($company_info) {
            $query = "UPDATE societe SET 
                      raison_sociale = :raison_sociale,
                      patente = :patente,
                      date_creation = :date_creation,
                      gerant = :gerant,
                      adresse = :adresse,
                      telephone_fixe = :telephone_fixe,
                      telephone_mobile = :telephone_mobile,
                      email = :email,
                      logo = :logo,
                      updated_at = NOW()
                      WHERE id = :id";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':id', $company_info['id']);
        } else {
            $query = "INSERT INTO societe (
                      raison_sociale, patente, date_creation, gerant, adresse, 
                      telephone_fixe, telephone_mobile, email, logo, created_at, updated_at
                      ) VALUES (
                      :raison_sociale, :patente, :date_creation, :gerant, :adresse,
                      :telephone_fixe, :telephone_mobile, :email, :logo, NOW(), NOW()
                      )";
            
            $stmt = $db->prepare($query);
        }
        
        $stmt->bindParam(':raison_sociale', $raison_sociale);
        $stmt->bindParam(':patente', $patente);
        $stmt->bindParam(':date_creation', $date_creation);
        $stmt->bindParam(':gerant', $gerant);
        $stmt->bindParam(':adresse', $adresse);
        $stmt->bindParam(':telephone_fixe', $telephone_fixe);
        $stmt->bindParam(':telephone_mobile', $telephone_mobile);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':logo', $logo_path);
        
        if ($stmt->execute()) {
            $success_message = "Les informations de la société ont été mises à jour avec succès.";
            
            // Récupérer les informations mises à jour
            $query = "SELECT * FROM societe LIMIT 1";
            $stmt = $db->prepare($query);
            $stmt->execute();
            $company_info = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $error_message = "Erreur lors de la mise à jour des informations.";
        }
    } catch (Exception $e) {
        $error_message = "Erreur: " . $e->getMessage();
    }
}

include $root_path . '/includes/header.php';
?>

<div class="flex h-screen bg-gray-50">
    <!-- Sidebar -->
    <?php include $root_path . '/includes/sidebar.php'; ?>

    <!-- Main Content -->
    <div class="flex-1 overflow-auto">
        <header class="bg-white shadow-sm">
            <div class="max-w-7xl mx-auto py-4 px-4 sm:px-6 lg:px-8">
                <h1 class="text-2xl font-bold text-gray-900">Paramètres de la société</h1>
            </div>
        </header>

        <main class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
            <?php if (!empty($success_message)): ?>
                <div class="mb-4 bg-green-100 border-l-4 border-green-500 text-green-700 p-4" role="alert">
                    <p><?php echo $success_message; ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="mb-4 bg-red-100 border-l-4 border-red-500 text-red-700 p-4" role="alert">
                    <p><?php echo $error_message; ?></p>
                </div>
            <?php endif; ?>

            <div class="bg-white shadow overflow-hidden sm:rounded-lg">
                <div class="px-4 py-5 sm:px-6">
                    <h3 class="text-lg leading-6 font-medium text-gray-900">Informations de la société</h3>
                    <p class="mt-1 max-w-2xl text-sm text-gray-500">Modifiez les informations de votre société qui apparaîtront sur les documents officiels.</p>
                </div>
                
                <form method="POST" enctype="multipart/form-data" class="border-t border-gray-200">
                    <div class="px-4 py-5 bg-gray-50 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                        <div class="col-span-3">
                            <div class="flex items-center">
                                <div class="mr-6">
                                    <?php if (!empty($company_info['logo'])): ?>
                                        <!-- Afficher le chemin pour débogage -->
                                        <p class="text-xs text-gray-500 mb-2">Chemin du logo: <?php echo htmlspecialchars('/sas-gestion-auto/' . $company_info['logo']); ?></p>
                                        <img src="<?php echo '/sas-gestion-auto/' . $company_info['logo']; ?>" alt="Logo de la société" class="h-32 w-auto object-contain border rounded p-2 bg-white">
                                    <?php else: ?>
                                        <div class="h-32 w-32 flex items-center justify-center border rounded p-2 bg-white text-gray-400">
                                            <svg class="h-16 w-16" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                            </svg>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <label for="logo" class="block text-sm font-medium text-gray-700">Logo de la société</label>
                                    <div class="mt-1">
                                        <input type="file" id="logo" name="logo" class="py-2 px-3 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                                    </div>
                                    <p class="mt-1 text-xs text-gray-500">Formats acceptés: JPEG, PNG, GIF. Taille maximale: 2MB.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="px-4 py-5 bg-white sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                        <dt class="text-sm font-medium text-gray-500">Raison sociale</dt>
                        <dd class="mt-1 text-sm text-gray-900 sm:mt-0 sm:col-span-2">
                            <input type="text" name="raison_sociale" id="raison_sociale" value="<?php echo htmlspecialchars($company_info['raison_sociale'] ?? ''); ?>" class="w-full py-2 px-3 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        </dd>
                    </div>
                    
                    <div class="px-4 py-5 bg-gray-50 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                        <dt class="text-sm font-medium text-gray-500">Patente</dt>
                        <dd class="mt-1 text-sm text-gray-900 sm:mt-0 sm:col-span-2">
                            <input type="text" name="patente" id="patente" value="<?php echo htmlspecialchars($company_info['patente'] ?? ''); ?>" class="w-full py-2 px-3 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        </dd>
                    </div>
                    
                    <div class="px-4 py-5 bg-white sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                        <dt class="text-sm font-medium text-gray-500">Date de création</dt>
                        <dd class="mt-1 text-sm text-gray-900 sm:mt-0 sm:col-span-2">
                            <input type="date" name="date_creation" id="date_creation" value="<?php echo htmlspecialchars($company_info['date_creation'] ?? ''); ?>" class="w-full py-2 px-3 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        </dd>
                    </div>
                    
                    <div class="px-4 py-5 bg-gray-50 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                        <dt class="text-sm font-medium text-gray-500">Nom du gérant</dt>
                        <dd class="mt-1 text-sm text-gray-900 sm:mt-0 sm:col-span-2">
                            <input type="text" name="gerant" id="gerant" value="<?php echo htmlspecialchars($company_info['gerant'] ?? ''); ?>" class="w-full py-2 px-3 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        </dd>
                    </div>
                    
                    <div class="px-4 py-5 bg-white sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                        <dt class="text-sm font-medium text-gray-500">Adresse du siège</dt>
                        <dd class="mt-1 text-sm text-gray-900 sm:mt-0 sm:col-span-2">
                            <textarea name="adresse" id="adresse" rows="3" class="w-full py-2 px-3 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm"><?php echo htmlspecialchars($company_info['adresse'] ?? ''); ?></textarea>
                        </dd>
                    </div>
                    
                    <div class="px-4 py-5 bg-gray-50 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                        <dt class="text-sm font-medium text-gray-500">Téléphone fixe</dt>
                        <dd class="mt-1 text-sm text-gray-900 sm:mt-0 sm:col-span-2">
                            <input type="tel" name="telephone_fixe" id="telephone_fixe" value="<?php echo htmlspecialchars($company_info['telephone_fixe'] ?? ''); ?>" class="w-full py-2 px-3 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        </dd>
                    </div>
                    
                    <div class="px-4 py-5 bg-white sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                        <dt class="text-sm font-medium text-gray-500">Téléphone mobile</dt>
                        <dd class="mt-1 text-sm text-gray-900 sm:mt-0 sm:col-span-2">
                            <input type="tel" name="telephone_mobile" id="telephone_mobile" value="<?php echo htmlspecialchars($company_info['telephone_mobile'] ?? ''); ?>" class="w-full py-2 px-3 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        </dd>
                    </div>
                    
                    <div class="px-4 py-5 bg-gray-50 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                        <dt class="text-sm font-medium text-gray-500">Email</dt>
                        <dd class="mt-1 text-sm text-gray-900 sm:mt-0 sm:col-span-2">
                            <input type="email" name="email" id="email" value="<?php echo htmlspecialchars($company_info['email'] ?? ''); ?>" class="w-full py-2 px-3 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm">
                        </dd>
                    </div>
                    
                    <div class="px-4 py-5 bg-white flex justify-end">
                        <button type="submit" class="inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                            Enregistrer les modifications
                        </button>
                    </div>
                </form>
            </div>
        </main>
    </div>
</div>

<?php include $root_path . '/includes/footer.php'; ?>
