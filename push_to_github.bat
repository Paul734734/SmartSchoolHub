@echo off
echo 🚀 SmartSchool Hub - Push sur GitHub
echo =====================================
echo.

REM Vérifier si Git est installé
git --version >nul 2>&1
if %errorlevel% neq 0 (
    echo ❌ Git n'est pas installé. Veuillez installer Git d'abord.
    echo 📥 Téléchargez Git sur : https://git-scm.com/download/win
    pause
    exit /b 1
)

echo ✅ Git est installé
echo.

REM Demander le nom d'utilisateur GitHub
set /p github_username="👤 Entrez votre nom d'utilisateur GitHub: "

REM Vérifier si le repository est déjà initialisé
if not exist ".git" (
    echo 📦 Initialisation du repository Git...
    git init
    echo.
)

REM Configurer l'utilisateur si nécessaire
echo ⚙️ Configuration de Git...
git config --global user.name "SmartSchool Developer"
git config --global user.email "developer@smartschoolhub.com"
echo.

REM Ajouter le remote si nécessaire
git remote -v | findstr "origin" >nul
if %errorlevel% neq 0 (
    echo 🔗 Ajout du remote origin...
    git remote add origin https://github.com/%github_username%/SmartSchoolHub.git
    echo.
)

REM Ajouter tous les fichiers
echo 📁 Ajout des fichiers au staging...
git add .
git add README.md
git add .gitignore
echo.

REM Commiter les changements
echo 💾 Commit des changements...
git commit -m "🎓 SmartSchool Hub v1.0 - Système de gestion scolaire complet

✨ Features principales:
- Tableaux de bord multi-rôles (Super-Admin, Admin, Enseignant, Parent, Élève)
- Gestion complète des classes, matières, élèves, enseignants
- Système de notes, absences, paiements et communications
- Interface moderne avec Bootstrap 5 et Font Awesome 6
- Scripts de configuration automatique
- Documentation professionnelle complète

🛠️ Stack technique:
- Backend: PHP 8.0+, MySQL/MariaDB
- Frontend: Bootstrap 5, Font Awesome 6
- Architecture: MVC Pattern
- Sécurité: Password hashing, Session management

📁 Structure organisée:
- dashboards/: Tableaux de bord par rôle
- scripts/: Scripts de configuration
- config/: Fichiers de configuration
- includes/: Fonctions utilitaires
- pages/: Pages spécifiques
- temp/: Fichiers temporaires
- legacy/: Anciens fichiers archivés

🔑 Comptes de démo inclus:
- Super-Admin: admin@demo.com / password
- Enseignant: jean.mbarga@demo.com / Teacher123!
- Parent: parent.joseph@demo.com / Parent123!
- Élève: paul.nkamga@demo.com / Student123!

🚀 Installation rapide:
1. Importer la base de données
2. Exécuter scripts/perfect_college_final.php
3. Se connecter via login_correct.php

📚 Documentation complète dans README.md"
echo.

REM Push sur GitHub
echo 🚀 Push sur GitHub...
git branch -M main
git push -u origin main
echo.

if %errorlevel% equ 0 (
    echo ✅ Push réussi !
    echo.
    echo 🔗 Votre repository est maintenant disponible sur:
    echo https://github.com/%github_username%/SmartSchoolHub
    echo.
    echo 📋 Prochaines étapes:
    echo 1. Visitez votre repository sur GitHub
    echo 2. Vérifiez que tous les fichiers sont bien présents
    echo 3. Testez l'installation depuis un clone frais
    echo 4. Partagez le lien avec vos collaborateurs
) else (
    echo ❌ Erreur lors du push
    echo.
    echo 💡 Solutions possibles:
    echo - Vérifiez votre nom d'utilisateur GitHub
    echo - Assurez-vous que le repository existe sur GitHub
    echo - Vérifiez votre connexion internet
    echo - Si c'est votre premier push, créez le repository sur GitHub d'abord
)

echo.
echo 🎓 SmartSchool Hub - Votre système de gestion scolaire moderne !
pause
