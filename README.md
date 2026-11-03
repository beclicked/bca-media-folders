# BCA Media Folders

Extension WordPress maison de Be Clicked Agency : ranger la médiathèque dans des dossiers, simplement.

## Principe

- **Dossiers virtuels** : aucun fichier n'est déplacé sur le serveur, les URLs ne changent jamais.
- Un média appartient à **un seul dossier** (ou à aucun : « Non classés »).
- Désactiver l'extension ne touche à rien. La **supprimer** efface les dossiers (les médias restent intacts).

## Fonctions (V1)

| Où | Quoi |
|---|---|
| Page Médias (grille et liste) | Arborescence latérale, dossiers et sous-dossiers, recherche de dossier |
| Page Médias | Glisser-déposer d'un ou plusieurs médias vers un dossier (sélection multiple en grille, cases cochées en liste) |
| Page Médias | Glisser un dossier sur un autre pour le déplacer, sur « Tous les médias » pour le remettre à la racine |
| Page Médias | Créer, renommer (double-clic ou F2), supprimer, au survol de chaque dossier |
| Uploads | Un fichier envoyé pendant qu'un dossier est ouvert est rangé dedans |
| Fenêtre de sélection (Gutenberg, Elementor, Elementor Pro, image mise en avant) | Filtre déroulant « Dossier » |
| Détail d'un média | Champ « Dossier » pour changer son rangement |

Hors V1 : arborescence complète dans la fenêtre de sélection, tri avancé, export ZIP, dossiers pour pages et articles.

## Droits

Capacité requise : `upload_files` (filtrable via `bcamf_capability`). Pour ranger un média, l'utilisateur doit aussi pouvoir le modifier (`edit_post`).

## Mises à jour automatiques

Basées sur GitHub Releases et le mécanisme natif « Update URI » de WordPress. Le client voit une mise à jour classique, sans mention de GitHub.

**Dépôt public** : rien à configurer.

**Dépôt privé** : ajouter dans `wp-config.php` de chaque site :

```php
define( 'BCA_GITHUB_TOKEN', 'github_pat_xxx' );
```

Token *fine-grained*, en lecture seule (Contents: Read), limité aux dépôts des extensions BCA. Jamais un token classique avec le scope `repo`.

## Publier une version

1. Passer la version dans `bca-media-folders.php` (en-tête `Version:` et constante `BCAMF_VERSION`).
2. Commit et push.
3. Sur GitHub : **Actions > Release > Run workflow**. (Pousser un tag `v1.0.1` fonctionne aussi.)

Le workflow `.github/workflows/release.yml` vérifie la cohérence des versions, refuse de republier une version existante, construit `bca-media-folders.zip`, crée le tag et publie la release. Les sites proposent la mise à jour dans les 12 heures, ou immédiatement via Tableau de bord > Mises à jour > Vérifier à nouveau. En SSH : `wp plugin update bca-media-folders`.

## Recette (à valider sur un site de test)

- [ ] Créer un dossier, un sous-dossier, renommer, supprimer
- [ ] Glisser un média, puis une sélection multiple, vers un dossier
- [ ] Vue « Non classés » : glisser un média dessus le retire de son dossier
- [ ] Déplacer un dossier dans un autre, puis à la racine
- [ ] Upload avec un dossier ouvert : le média arrive dedans
- [ ] Vue liste : filtre, recherche, pagination conservent le dossier
- [ ] Elementor Pro : widget Image, filtre « Dossier » présent et fonctionnel
- [ ] Gutenberg : bloc Image et image mise en avant
- [ ] Détail d'un média : changer le dossier, compteurs mis à jour
- [ ] Compte « Auteur » : ne peut ranger que ses propres médias
