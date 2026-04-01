# 🚀 SmartSchool Hub - Push sur GitHub (PowerShell)

Write-Host "🚀 SmartSchool Hub - Push sur GitHub" -ForegroundColor Cyan
Write-Host "=====================================" -ForegroundColor Cyan
Write-Host ""

# Vérifier si Git est installé
try {
    git --version | Out-Null
    Write-Host "✅ Git est installé" -ForegroundColor Green
} catch {
    Write-Host "❌ Git n'est pas installé. Veuillez installer Git d'abord." -ForegroundColor Red
    Write-Host "📥 Téléchargez Git sur : https://git-scm.com/download/win" -ForegroundColor Yellow
    Read-Host "Appuyez sur Entrée pour quitter"
    exit 1
}

Write-Host ""

# Demander le nom d'utilisateur GitHub
$githubUsername = Read-Host "👤 Entrez votre nom d'utilisateur GitHub"

# Vérifier si le repository est déjà initialisé
if (-not (Test-Path ".git")) {
    Write-Host "📦 Initialisation du repository Git..." -ForegroundColor Yellow
    git init
    Write-Host ""
}

# Configurer l'utilisateur si nécessaire
Write-Host "⚙️ Configuration de Git..." -ForegroundColor Yellow
git config --global user.name "SmartSchool Developer"
git config --global user.email "developer@smartschoolhub.com"
Write-Host ""

# Ajouter le remote si nécessaire
$remoteCheck = git remote -v
if ($remoteCheck -notlike "*origin*") {
    Write-Host "🔗 Ajout du remote origin..." -ForegroundColor Yellow
    git remote add origin "https://github.com/$githubUsername/SmartSchoolHub.git"
    Write-Host ""
}

# Ajouter tous les fichiers
Write-Host "📁 Ajout des fichiers au staging..." -ForegroundColor Yellow
git add .
git add README.md
git add .gitignore
Write-Host ""

# Commiter les changements
Write-Host "💾 Commit des changements..." -ForegroundColor Yellow
git commit -m @"
🎓 SmartSchool Hub v1.0 - Système de gestion scolaire complet

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

📚 Documentation complète dans README.md
"@
Write-Host ""

# Push sur GitHub
Write-Host "🚀 Push sur GitHub..." -ForegroundColor Yellow
git branch -M main
$pushResult = git push -u origin main

if ($LASTEXITCODE -eq 0) {
    Write-Host "✅ Push réussi !" -ForegroundColor Green
    Write-Host ""
    Write-Host "🔗 Votre repository est maintenant disponible sur:" -ForegroundColor Cyan
    Write-Host "https://github.com/$githubUsername/SmartSchoolHub" -ForegroundColor White
    Write-Host ""
    Write-Host "📋 Prochaines étapes:" -ForegroundColor Yellow
    Write-Host "1. Visitez votre repository sur GitHub"
    Write-Host "2. Vérifiez que tous les fichiers sont bien présents"
    Write-Host "3. Testez l'installation depuis un clone frais"
    Write-Host "4. Partagez le lien avec vos collaborateurs"
} else {
    Write-Host "❌ Erreur lors du push" -ForegroundColor Red
    Write-Host ""
    Write-Host "💡 Solutions possibles:" -ForegroundColor Yellow
    Write-Host "- Vérifiez votre nom d'utilisateur GitHub"
    Write-Host "- Assurez-vous que le repository existe sur GitHub"
    Write-Host "- Vérifiez votre connexion internet"
    Write-Host "- Si c'est votre premier push, créez le repository sur GitHub d'abord"
}

Write-Host ""
Write-Host "🎓 SmartSchool Hub - Votre système de gestion scolaire moderne !" -ForegroundColor Cyan
Read-Host "Appuyez sur Entrée pour quitter"
