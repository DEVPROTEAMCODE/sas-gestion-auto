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
include '../includes/header.php';

$database = new Database();
$db = $database->getConnection();

// Vérifier si l'ID de l'article est fourni
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header('Location: index.php');
    exit;
}

$article_id = intval($_GET['id']);

// Récupérer les informations de l'article
try {
    $query = "SELECT * FROM articles WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $article_id);
    $stmt->execute();
    
    if ($stmt->rowCount() === 0) {
        header('Location: index.php');
        exit;
    }
    
    $article = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_message = "Erreur lors de la récupération des informations de l'article: " . $e->getMessage();
}

// Récupérer toutes les catégories de la base de données
$categoriesQuery = "SELECT * FROM categorie ORDER BY nom";
$categoriesStmt = $db->prepare($categoriesQuery);
$categoriesStmt->execute();
$categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

// Traitement du formulaire
$errors = [];
$success = false;
$message = '';
$article_updated = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validation des données
    if (empty($_POST['reference'])) {
        $errors['reference'] = 'La référence est requise';
    }
    if (empty($_POST['designation'])) {
        $errors['designation'] = 'La désignation est requise';
    }

    if (empty($_POST['categorie'])) {
        $errors['categorie'] = 'La catégorie est requise';
    }

    if (empty($_POST['prix_achat'])) {
        $errors['prix_achat'] = 'Le prix d\'achat est requis';
    } else if ($_POST['prix_achat'] <= 0) {
        $errors['prix_achat'] = 'Le prix d\'achat doit être supérieur à 0';
    }

    if (empty($_POST['marge_benifice'])) {
        $errors['marge_benifice'] = 'La marge bénéfice est requise';
    } else if ($_POST['marge_benifice'] < 0 || $_POST['marge_benifice'] > 100) {
        $errors['marge_benifice'] = 'La marge doit être comprise entre 0 et 100%';
    }

    if (empty($_POST['quantite_stock'])) {
        $errors['quantite_stock'] = 'La quantité en stock est requise';
    } else if ($_POST['quantite_stock'] < 0) {
        $errors['quantite_stock'] = 'La quantité doit être un nombre positif';
    }

    if (empty($_POST['seuil_alerte'])) {
        $errors['seuil_alerte'] = 'Le seuil d\'alerte est requis';
    } else if ($_POST['seuil_alerte'] < 0) {
        $errors['seuil_alerte'] = 'Le seuil d\'alerte doit être un nombre positif';
    }

    if (empty($_POST['emplacement'])) {
        $errors['emplacement'] = 'L\'emplacement est requis';
    }

    // Si aucune erreur, mettre à jour l'article dans la base de données
    if (empty($errors)) {
        try {
            // Utiliser la connexion de database.php
            global $db;

            // Calcul des prix de vente
            $prix_achat = floatval($_POST['prix_achat']);
            $marge = floatval($_POST['marge_benifice']);
            
            $prix_vente_ht = $prix_achat * (1 + $marge / 100);

            // Préparer la requête de mise à jour
            $query = "UPDATE articles SET 
                        reference = :reference, 
                        designation = :designation, 
                        categorie_id = :categorie, 
                        emplacement = :emplacement, 
                        prix_achat = :prix_achat, 
                        marge_benifice = :marge_benifice,  
                        prix_vente_ht = :prix_vente_ht, 
                        quantite_stock = :quantite_stock, 
                        seuil_alerte = :seuil_alerte, 
                        derniere_mise_a_jour = :derniere_mise_a_jour
                      WHERE id = :id";
            
            $stmt = $db->prepare($query);

            // Binder les paramètres
            $stmt->bindParam(':reference', $_POST['reference']);
            $stmt->bindParam(':designation', $_POST['designation']);
            $stmt->bindParam(':categorie', $_POST['categorie']);
            $stmt->bindParam(':emplacement', $_POST['emplacement']);
            $stmt->bindParam(':prix_achat', $prix_achat);
            $stmt->bindParam(':marge_benifice', $marge);
            $stmt->bindParam(':prix_vente_ht', $prix_vente_ht);
            $stmt->bindParam(':quantite_stock', $_POST['quantite_stock'], PDO::PARAM_INT);
            $stmt->bindParam(':seuil_alerte', $_POST['seuil_alerte'], PDO::PARAM_INT);
            
            $date_mise_a_jour = date('Y-m-d H:i:s');
            $stmt->bindParam(':derniere_mise_a_jour', $date_mise_a_jour);
            $stmt->bindParam(':id', $article_id, PDO::PARAM_INT);

            // Exécuter la requête
            if ($stmt->execute()) {
                $success = true;
                $article_updated = true;
                $message = "L'article a été mis à jour avec succès!";
                
                // Récupérer les informations mises à jour de l'article
                $query = "SELECT * FROM articles WHERE id = :id";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':id', $article_id);
                $stmt->execute();
                $article = $stmt->fetch(PDO::FETCH_ASSOC);
            } else {
                $message = "Erreur lors de la mise à jour de l'article.";
            }
        } catch (PDOException $e) {
            $message = "Erreur de base de données: " . $e->getMessage();
        }
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
            <h1 class="text-2xl font-bold text-gray-800 mb-6">Modifier un Article</h1>
            
            <?php if ($success): ?>
                <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                    <?php echo $message; ?>
                </div>
                
                <!-- Boutons de navigation après modification réussie -->
                <div class="flex space-x-4 mb-6">
                    <a href="index.php" class="px-4 py-2 bg-gray-600 text-white rounded-md hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500">
                        Retour à la liste des articles
                    </a>
                    <a href="add.php" class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        Ajouter un nouvel article
                    </a>
                </div>
            <?php elseif (!empty($message)): ?>
                <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            
            <?php if (!$article_updated): ?>
            <div class="mb-6">
                <h2 class="text-xl font-semibold text-gray-700 mb-2">Modification de l'Article #<?php echo $article_id; ?></h2>
                <p class="text-gray-600">Tous les champs marqués d'un * sont obligatoires</p>
            </div>
            
            <form id="article-form" method="POST" action="">
                <!-- Section Informations Générales -->
                <div class="mb-6 p-4 bg-gray-50 rounded-md border-l-4 border-blue-500">
                    <h3 class="text-lg font-medium text-gray-700 mb-4">Informations Générales</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="reference" class="block text-sm font-medium text-gray-700 mb-1">Référence*</label>
                            <input type="text" id="reference" name="reference" placeholder="Entrez la référence" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                value="<?php echo htmlspecialchars($article['reference']); ?>">
                            <?php if (isset($errors['reference'])): ?>
                                <div class="text-red-500 text-xs mt-1"><?php echo $errors['reference']; ?></div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="designation" class="block text-sm font-medium text-gray-700 mb-1">Désignation*</label>
                            <input type="text" id="designation" name="designation" placeholder="Nom de l'article" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                value="<?php echo htmlspecialchars($article['designation']); ?>">
                            <?php if (isset($errors['designation'])): ?>
                                <div class="text-red-500 text-xs mt-1"><?php echo $errors['designation']; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                        <div>
                            <label for="categorie" class="block text-sm font-medium text-gray-700 mb-1">Catégorie*</label>
                            <select id="categorie" name="categorie" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Sélectionner une catégorie</option>
                                <?php foreach ($categories as $categorie): ?>
    <?php $selected = ($article['categorie_id'] == $categorie['id']) ? 'selected' : ''; ?>
    <option value="<?php echo $categorie['id']; ?>" <?php echo $selected; ?>>
        <?php echo htmlspecialchars($categorie['nom']); ?>
    </option>
<?php endforeach; ?>

                            </select>
                            <?php if (isset($errors['categorie'])): ?>
                                <div class="text-red-500 text-xs mt-1"><?php echo $errors['categorie']; ?></div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="emplacement" class="block text-sm font-medium text-gray-700 mb-1">Emplacement*</label>
                            <select id="emplacement" name="emplacement" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500">
                                <option value="">Sélectionner un emplacement</option>
                                <?php
                                $emplacements = [
                                  'Casa Roche noir', 'Casa Aeroport', 'Rabat', 'Marrakech', 
                                    'Marrakech Aeroport'
                                ];
                                foreach ($emplacements as $emp) {
                                    $selected = ($article['emplacement'] === $emp) ? 'selected' : '';
                                    echo "<option value=\"$emp\" $selected>$emp</option>";
                                }
                                ?>
                            </select>
                            <?php if (isset($errors['emplacement'])): ?>
                                <div class="text-red-500 text-xs mt-1"><?php echo $errors['emplacement']; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Section Informations Financières -->
                <div class="mb-6 p-4 bg-gray-50 rounded-md border-l-4 border-blue-500">
                    <h3 class="text-lg font-medium text-gray-700 mb-4">Informations Financières</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="prix_achat" class="block text-sm font-medium text-gray-700 mb-1">Prix d'achat (DH HT)*</label>
                            <input type="number" id="prix_achat" name="prix_achat" step="0.01" min="0" placeholder="0.00" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                value="<?php echo htmlspecialchars($article['prix_achat']); ?>">
                            <?php if (isset($errors['prix_achat'])): ?>
                                <div class="text-red-500 text-xs mt-1"><?php echo $errors['prix_achat']; ?></div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="marge_benifice" class="block text-sm font-medium text-gray-700 mb-1">Marge bénéfice (%)*</label>
                            <input type="number" id="marge_benifice" name="marge_benifice" step="0.1" min="0" max="100" placeholder="20.0" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                value="<?php echo htmlspecialchars($article['marge_benifice']); ?>">
                            <?php if (isset($errors['marge_benifice'])): ?>
                                <div class="text-red-500 text-xs mt-1"><?php echo $errors['marge_benifice']; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-1 gap-4 mt-4">
                        <div>
                            <label for="prix_vente_ht" class="block text-sm font-medium text-gray-700 mb-1">Prix de vente (€ HT)</label>
                            <input type="number" id="prix_vente_ht" name="prix_vente_ht" step="0.01" min="0" readonly
                                class="w-full px-3 py-2 bg-gray-100 border border-gray-300 rounded-md"
                                value="<?php echo htmlspecialchars($article['prix_vente_ht']); ?>">
                            <span class="text-xs text-gray-500 mt-1 block">Calculé automatiquement</span>
                        </div>
                    </div>
                </div>
                
                <!-- Section Gestion des Stocks -->
                <div class="mb-6 p-4 bg-gray-50 rounded-md border-l-4 border-blue-500">
                    <h3 class="text-lg font-medium text-gray-700 mb-4">Gestion des Stocks</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="quantite_stock" class="block text-sm font-medium text-gray-700 mb-1">Quantité en stock*</label>
                            <input type="number" id="quantite_stock" name="quantite_stock" min="0" placeholder="0" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                value="<?php echo htmlspecialchars($article['quantite_stock']); ?>">
                            <?php if (isset($errors['quantite_stock'])): ?>
                                <div class="text-red-500 text-xs mt-1"><?php echo $errors['quantite_stock']; ?></div>
                            <?php endif; ?>
                        </div>
                        <div>
                            <label for="seuil_alerte" class="block text-sm font-medium text-gray-700 mb-1">Seuil d'alerte*</label>
                            <input type="number" id="seuil_alerte" name="seuil_alerte" min="0" placeholder="5" required
                                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
                                value="<?php echo htmlspecialchars($article['seuil_alerte']); ?>">
                            <span class="text-xs text-gray-500 mt-1 block">Niveau minimum avant réapprovisionnement</span>
                            <?php if (isset($errors['seuil_alerte'])): ?>
                                <div class="text-red-500 text-xs mt-1"><?php echo $errors['seuil_alerte']; ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- Section Dates -->
                <div class="mb-6 p-4 bg-gray-50 rounded-md border-l-4 border-blue-500">
                    <h3 class="text-lg font-medium text-gray-700 mb-4">Dates</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="date_creation" class="block text-sm font-medium text-gray-700 mb-1">Date de création</label>
                            <input type="text" id="date_creation" name="date_creation" readonly
                                class="w-full px-3 py-2 bg-gray-100 border border-gray-300 rounded-md"
                                value="<?php echo date('d/m/Y H:i', strtotime($article['date_creation'])); ?>">
                            <span class="text-xs text-gray-500 mt-1 block">Date de création initiale</span>
                        </div>
                        <div>
                            <label for="derniere_mise_a_jour" class="block text-sm font-medium text-gray-700 mb-1">Dernière mise à jour</label>
                            <input type="text" id="derniere_mise_a_jour" name="derniere_mise_a_jour" readonly
                                class="w-full px-3 py-2 bg-gray-100 border border-gray-300 rounded-md"
                                value="<?php echo date('d/m/Y H:i', strtotime($article['derniere_mise_a_jour'])); ?>">
                            <span class="text-xs text-gray-500 mt-1 block">Sera mise à jour automatiquement</span>
                        </div>
                    </div>
                </div>
                
                <!-- Boutons d'action -->
                <div class="flex justify-end space-x-4 mt-6">
                    <a href="index.php" class="px-4 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 focus:outline-none focus:ring-2 focus:ring-gray-500">
                        Annuler
                    </a>
                    <button type="submit" id="submit-btn" 
                        class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        Enregistrer les modifications
                    </button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Setup price calculations
        setupPriceCalculations();
        
        // Setup form validation
        setupValidation();
    });
    
    // Setup automatic price calculations
    function setupPriceCalculations() {
        const prixAchatInput = document.getElementById('prix_achat');
        const margeBenificeInput = document.getElementById('marge_benifice');
        const prixVenteHtInput = document.getElementById('prix_vente_ht');
        
        if (!prixAchatInput || !margeBenificeInput || !prixVenteHtInput) return;
        
        // Function to calculate prices
        function calculatePrices() {
            const prixAchat = parseFloat(prixAchatInput.value) || 0;
            const margeBenifice = parseFloat(margeBenificeInput.value) || 0;
            
            // Calculate HT price with margin
            const prixVenteHt = prixAchat * (1 + margeBenifice / 100);
            
            // Update fields with 2 decimal places
            prixVenteHtInput.value = prixVenteHt.toFixed(2);
        }
        
        // Add event listeners
        prixAchatInput.addEventListener('input', calculatePrices);
        margeBenificeInput.addEventListener('input', calculatePrices);
        
        // Calculate initial values
        calculatePrices();
    }
    
    // Setup form validation
    function setupValidation() {
        const form = document.getElementById('article-form');
        if (!form) return;
        
        const fields = {
            reference: {
                required: true,
                errorMsg: 'La référence est requise'
            },
            designation: {
                required: true,
                minLength: 3,
                errorMsg: 'Minimum 3 caractères requis'
            },
            categorie: {
                required: true,
                errorMsg: 'Veuillez sélectionner une catégorie'
            },
            prix_achat: {
                required: true,
                min: 0.01,
                errorMsg: 'Le prix d\'achat doit être supérieur à 0'
            },
            marge_benifice: {
                required: true,
                min: 0,
                max: 100,
                errorMsg: 'La marge doit être comprise entre 0 et 100%'
            },
            quantite_stock: {
                required: true,
                min: 0,
                errorMsg: 'La quantité doit être un nombre positif'
            },
            seuil_alerte: {
                required: true,
                min: 0,
                errorMsg: 'Le seuil d\'alerte doit être un nombre positif'
            },
            emplacement: {
                required: true,
                errorMsg: 'Veuillez sélectionner un emplacement'
            }
        };
        
        // Validate a single field
        function validateField(fieldName, value) {
            const field = fields[fieldName];
            let errorElement = document.getElementById(`${fieldName}-error`);
            
            if (!field) return true;
            
            // Create error element if it doesn't exist
            if (!errorElement) {
                errorElement = document.createElement('div');
                errorElement.id = `${fieldName}-error`;
                errorElement.className = 'text-red-500 text-xs mt-1';
                const fieldElement = document.getElementById(fieldName);
                if (fieldElement && fieldElement.parentNode) {
                    fieldElement.parentNode.appendChild(errorElement);
                }
            }
            
            // Check required
            if (field.required && !value) {
                errorElement.textContent = 'Ce champ est obligatoire';
                return false;
            }
            
            // Check min length
            if (field.minLength && value && value.length < field.minLength) {
                errorElement.textContent = field.errorMsg;
                return false;
            }
            
            // Check min value
            if (field.min !== undefined && value !== '' && parseFloat(value) < field.min) {
                errorElement.textContent = field.errorMsg;
                return false;
            }
            
            // Check max value
            if (field.max !== undefined && value !== '' && parseFloat(value) > field.max) {
                errorElement.textContent = field.errorMsg;
                return false;
            }
            
            // Clear error if validation passes
            errorElement.textContent = '';
            return true;
        }
        
        // Add input event listeners to all fields
        Object.keys(fields).forEach(fieldName => {
            const element = document.getElementById(fieldName);
            if (element) {
                element.addEventListener('input', function() {
                    validateField(fieldName, this.value);
                });
                
                element.addEventListener('blur', function() {
                    validateField(fieldName, this.value);
                });
            }
        });
        
        // Validate all fields before form submission
        form.addEventListener('submit', function(event) {
            let isValid = true;
            
            Object.keys(fields).forEach(fieldName => {
                const element = document.getElementById(fieldName);
                if (element) {
                    const fieldValid = validateField(fieldName, element.value);
                    isValid = isValid && fieldValid;
                }
            });
            
            if (!isValid) {
                event.preventDefault();
                showStatus('Veuillez corriger les erreurs dans le formulaire.', 'error');
            }
        });
    }
    
    // Show status message
    function showStatus(message, type) {
        // Create status container if it doesn't exist
        let statusContainer = document.getElementById('status-message');
        if (!statusContainer) {
            statusContainer = document.createElement('div');
            statusContainer.id = 'status-message';
            const bgWhite = document.querySelector('.bg-white');
            const articleForm = document.getElementById('article-form');
            
            if (bgWhite && articleForm) {
                bgWhite.insertBefore(statusContainer, articleForm);
            } else if (bgWhite) {
                bgWhite.prepend(statusContainer);
            } else {
                document.body.prepend(statusContainer);
            }
        }
        
        // Set content and styling
        statusContainer.textContent = message;
        statusContainer.className = 'px-4 py-3 rounded mb-4 ';
        
        if (type === 'success') {
            statusContainer.className += 'bg-green-100 border border-green-400 text-green-700';
        } else {
            statusContainer.className += 'bg-red-100 border border-red-400 text-red-700';
        }
        
        // Auto-hide after 5 seconds
        setTimeout(() => {
            statusContainer.remove();
        }, 5000);
    }
</script>

<?php
// Inclure le pied de page
include $root_path . '/includes/footer.php';
?>
