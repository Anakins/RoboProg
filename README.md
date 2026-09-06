# RoboProg

Planificateur de tonte **générique**, compatible avec n'importe quel robot tondeuse déjà intégré à Jeedom (Worx, Husqvarna, Gardena, Bosch...), du moment qu'il expose au moins une commande de démarrage.

## Principe

Contrairement à un plugin dédié à une seule marque de robot, **RoboProg ne communique jamais directement avec le robot**. Il se contente de piloter les commandes d'un équipement robot déjà existant dans Jeedom (démarrer, retour à la base, bordures...) au bon moment, selon des règles météo et un calendrier configurables.

RoboProg crée son propre équipement ("une programmation"), séparé de l'équipement du robot. Toutes les commandes robot utilisées sont des **références** (tags Jeedom, ou sélection via l'icône verte à côté de chaque champ) vers les commandes de l'équipement robot d'origine.

## 1. Commandes obligatoires

Sans ces deux commandes, impossible d'activer la programmation (le bouton "Tester" renverra une erreur) :

| Commande | Rôle |
|---|---|
| **Nom du robot** | Champ texte libre (pas une commande Jeedom). Utilisé dans tous les messages de notification et les logs, à la place du nom technique de l'équipement. Doit contenir au moins une lettre (un nom purement numérique est refusé). |
| **Commande pour lancer la tonte** | Commande **action** qui déclenche une tonte sur le robot. C'est le seul geste que RoboProg doit absolument pouvoir faire pour fonctionner. |
| **Humidité** | Commande **info** numérique (%), fournie par un plugin météo tiers. C'est le **cœur du plugin**, pas un simple garde-fou : c'est elle qui calcule si le sol a eu le temps de sécher (seuil + délai de confirmation), afin de ne jamais tondre un sol détrempé et abîmer la pelouse. C'est précisément la raison d'être de RoboProg : offrir une programmation qui **protège la pelouse**, quitte à refuser de tondre par temps orageux ou de bruine — contrairement à une programmation "bête" à heure fixe. Ce choix est volontaire et non contournable. |
| **Condition météo** | Deux commandes **info**, fournies par un plugin météo tiers : le **code numérique** (convention OpenWeatherMap) et le **libellé** (informatif). Complète l'humidité en filtrant aussi les conditions instables (orage, pluie en cours...) : la tonte n'est autorisée que pour une liste de codes **figée en dur dans le plugin** (temps sec/dégagé), non modifiable par l'utilisateur. |

## 2. Commandes optionnelles et ce qu'elles débloquent

Chaque commande supplémentaire liée débloque un bloc de fonctions précis. Rien ne vous empêche de n'en lier aucune : RoboProg reste utilisable avec seulement le socle obligatoire ci-dessus (tonte à heure fixe, coupée par l'humidité **et** la condition météo).

| Commande liée | Débloque |
|---|---|
| **Commande de retour à la maison** | Combinée à un **capteur pluie** configuré, elle permet de **rappeler activement le robot** dès que la pluie est détectée (voir section 4). Combinée à Statut + valeur "à la maison" enregistrée, elle permet aussi la relance automatique du cycle classique après un rattrapage de bordures (voir section 5). Si vous ne voulez pas du tout gérer la pluie, laissez simplement le capteur pluie et/ou cette commande vides : sans les deux ensemble, le robot ne sera jamais rappelé automatiquement. |
| **Commande pour lancer la tonte des bordures** | À cocher uniquement si votre robot **ne coupe pas les bordures automatiquement à chaque tonte** et nécessite une commande séparée. Une case dédiée ("Mon robot ne fait pas les bordures automatiquement") révèle le champ de commande et toute la section "Programmation des bordures" (section 5). |
| **Commande de statut** + valeur "à la maison" enregistrée | La détection fiable du retour à la base. Nécessaire, avec la commande de retour à la maison, pour la relance automatique après bordures. |
| **Commande de batterie** | Une vérification de sécurité (seuil configurable) avant CHAQUE envoi de commande de démarrage (tonte classique, bordures, rattrapage, relance) — pas seulement une fois, mais systématiquement, via une fonction unique partagée par tous les déclenchements. Sans cette commande, RoboProg envoie ses commandes sans jamais vérifier la batterie : si le robot refuse de partir faute de charge suffisante, RoboProg n'en saura rien et considérera à tort la tonte comme lancée. |
| **Commande d'erreur** | Affiche la dernière erreur signalée par le robot (n'importe quelle valeur est acceptée, y compris vide). Peut aussi servir de source pour le capteur pluie (ex: un code d'erreur "Rain Delay" chez certaines marques), et être ajoutée aux destinataires de notifications pour être alertée en cas de souci. |
| **Capteurs pluie** | Détection de la pluie en temps réel, avec rappel actif du robot si la commande de retour à la maison est aussi configurée (voir section 4). Sans capteur pluie, seule l'humidité protège contre la pluie — mais elle ne fait que retarder une future tonte, elle ne peut pas rappeler un robot déjà en train de tondre. |

### Comment ces commandes s'articulent entre elles

- **Retour à la maison** + **capteur pluie**, tous deux configurés : dès que la pluie est détectée, le robot est rappelé activement, et la tonte en cours (le cas échéant) est invalidée et retentée plus tard (voir "Durée de tonte estimée", section 4).
- **Retour à la maison** sans **capteur pluie** (ou l'inverse) : la pluie n'est jamais détectée, donc le robot n'est jamais rappelé automatiquement — RoboProg se contente alors de ne pas déclencher de nouvelle tonte tant que l'humidité/la condition météo ne sont pas favorables.
- **Statut** seul (sans retour à la maison) : permet d'afficher l'état du robot, mais RoboProg n'a aucun moyen de le faire rentrer de force.
- **Retour à la maison + Statut + valeur "à la maison" enregistrée** : combinaison complète nécessaire pour la relance automatique après bordures (section 5) — les trois sont nécessaires ensemble, RoboProg refuse d'activer cette option si l'un des trois manque.
- **Bordures** sans **retour à la maison**/**Statut** : la coupe des bordures elle-même fonctionne (jours fixes ou intervalle, rattrapage), mais l'option de relance automatique du cycle classique après le rattrapage reste indisponible (case grisée/refusée).
- **Batterie** : totalement indépendante des autres — s'applique systématiquement dès qu'elle est liée, quel que soit le contexte (tonte classique, bordures, rattrapage, relance).

## 3. Plage horaire et espacement

Commande (tag Jeedom) ou heure fixe pour le début/la fin au format `HMM`/`HHMM`), marge de sécurité avant l'heure de fin, et espacement en jours entre deux tontes classiques.

## 4. Météo et pluie

- **Humidité** (obligatoire) : seuil (%) et délai (min) avant de considérer la pelouse suffisamment sèche. C'est le mécanisme central du plugin (voir section 1).
- **Condition météo** (obligatoire) : deux commandes (code numérique OpenWeatherMap + libellé informatif). La liste des codes acceptés pour tondre est **figée en dur** (temps sec/dégagé), pas configurable par l'utilisateur.
- **Température** (optionnel) : protection gel (4 à 18°C, défaut 8°C) et canicule (30 à 50°C, défaut 40°C). Laisser le champ vide pour ignorer ce critère.
- **Capteurs pluie** (optionnels, deux possibles) : contrairement à un robot dédié où la convention est fixe ("Oui"/"Non"), RoboProg ne peut pas savoir à l'avance ce que vos commandes renvoient pour signaler la pluie. Vous configurez vous-même, pour chacun, une **condition de comparaison** (opérateur `==`/`≠` et une valeur). Les deux capteurs sont indépendants : l'un ou l'autre suffit à déclencher la détection de pluie (logique OU). Utilisez-en un (le capteur du robot, par exemple `Commande d'erreur == 5` s'il n'a pas de capteur dédié) ou les deux (robot + station météo externe), selon ce dont vous disposez. **Combinés à la commande de retour à la maison**, ils permettent de rappeler activement le robot dès que la pluie est détectée — sans capteur pluie configuré, ce rappel actif n'a jamais lieu, seule l'humidité protège les tontes futures.
- **Durée de tonte estimée** (30 à 360 min, défaut 120 min) : distincte de la marge (section 3). Sert à faire la différence entre une vraie pluie pendant une tonte en cours (retour forcé + tonte invalidée, retentée après le délai ci-dessous) et une fausse alerte survenant bien plus tard le même jour, sans rapport avec une tonte déjà terminée depuis longtemps — cas fréquent pour un robot sans garage, dont le capteur peut se déclencher des heures après coup. Une tonte n'est considérée "en cours" (et donc annulable par la pluie) que pendant cette durée après son déclenchement ; passé ce délai, la pluie détectée n'a plus d'effet sur cette tonte (déjà considérée terminée), mais continue bien sûr d'empêcher toute nouvelle tonte de démarrer.
- **Délai avant redémarrage après pluie** (20 à 120 min, défaut 60 min) : une fois une tonte invalidée par la pluie, délai minimum avant de retenter, indépendamment du retour de l'humidité sous le seuil (le temps que le sol absorbe une grosse averse). Si la commande de statut et la valeur "à la maison" sont enregistrées, RoboProg attend en plus la confirmation que le robot est effectivement rentré avant de retenter (plus fiable qu'un délai fixe seul). L'état de cette attente est visible en temps réel dans le tableau "État des conditions de démarrage" (section 8).

## 5. Programmation des bordures

⚠️ **Cette fonctionnalité ne concerne que les robots qui ne font pas systématiquement les bordures à chaque tonte.** Certains robots intègrent la coupe des bordures automatiquement dans chaque cycle de tonte classique (pas besoin de cette section, ni même de lier la commande pour lancer la tonte des bordures). D'autres — c'est pour ceux-là que cette section existe — ne coupent les bordures que sur demande explicite, via une commande dédiée et séparée du démarrage classique. Si votre robot fait partie de la première catégorie, ignorez purement et simplement cette section (ne liez pas la commande pour lancer la tonte des bordures, et rien ne s'affichera).

Visible uniquement si la commande pour lancer la tonte des bordures est liée.

- **Mode** : soit des **jours de la semaine** fixes (cases à cocher), soit un **intervalle** en jours (1 à 7 — 7 = toutes les semaines). Ce cycle est indépendant de l'espacement de la tonte classique : les bordures se déclenchent sur leur propre cadence, même un jour où la tonte classique n'est pas due.
- **Rattrapage** (case à cocher) : si le jour prévu pour les bordures est manqué (conditions météo non réunies toute la journée), le rattrapage se fait automatiquement — **uniquement le jour où une tonte classique était de toute façon prévue** (espacement respecté). Il remplace alors la tonte classique de ce jour-là par les bordures. Si ce jour de rattrapage est lui-même bloqué par la météo, rien ne se passe et le rattrapage reste en attente pour le prochain jour de tonte classique valide (retenté automatiquement, jour après jour, tant qu'il n'a pas pu avoir lieu).
- **Relance automatique du cycle classique** (case à cocher) : après **n'importe quelle** coupe de bordures — rattrapage **ou** jour normal programmé — RoboProg peut relancer automatiquement la tonte classique une fois le robot de retour à la maison. Elle n'est plus liée à la case rattrapage : cochez-la même si vous voulez juste enchaîner bordures → tonte classique un jour de bordures ordinaire.
  - **⚠️ Obligatoire si les bordures sont programmées TOUS les jours** (intervalle = 1, ou les 7 jours de la semaine cochés) : sans elle, la tonte classique ne pourrait jamais avoir lieu. C'est **bloquant** — le test et la sauvegarde (activation) refuseront cette configuration tant que la relance n'est pas activée.
  - **S'il ne pleut pas** : les **mêmes conditions de sécurité que pour un démarrage classique** sont revérifiées avant de relancer Démarrer (humidité, température si liée, batterie si liée). Si l'une d'elles n'est pas réunie, RoboProg patiente et retente à chaque cycle (5 min), jusqu'à 4h maximum ; passé ce délai, la relance est abandonnée et la tonte classique reprendra normalement à son prochain cycle valide (selon l'espacement configuré). **Si aucune commande Batterie n'est liée**, seule cette vérification est ignorée (départ dès que le reste est réuni).
  - **S'il pleut** au moment du retour : RoboProg considère que les bordures ont probablement été **interrompues par la pluie** plutôt que terminées normalement. La tonte classique n'est **pas** relancée, et un rattrapage de bordures est programmé pour plus tard. ⚠️ Cette distinction repose entièrement sur le(s) capteur(s) pluie : **sans aucun capteur pluie configuré**, RoboProg ne peut jamais savoir si un retour sous la pluie signifie "interrompu" ou "bordures normalement terminées, il se met juste à pleuvoir après" — il suppose alors systématiquement que les bordures sont terminées normalement, et relance la tonte classique.
  - Nécessite les commandes de retour à la maison + Statut, et d'avoir enregistré la valeur "à la maison" (bouton dédié dans le formulaire).

Ce mécanisme peut générer **jusqu'à deux notifications distinctes** : une au moment du rattrapage des bordures (y compris en cas d'interruption détectée par la pluie au retour), une seconde uniquement si la relance de la tonte classique a bien pu avoir lieu.

## 6. Notifications

Autant de commandes que voulu (Discord, appli mobile...), avec titre personnalisable et choix du format (HTML avec `<br/>`, ou texte brut). Quatre cases à cocher par destinataire permettent de choisir précisément qui reçoit quoi :
- **Pas de tonte** (cochée par défaut) : envoyée en fin de fenêtre horaire si la tonte classique n'a pas pu avoir lieu.
- **Erreur** (décochée par défaut) : si une commande Erreur est liée.
- **Rattrapage bordures** / **Relance** (décochées par défaut) : les deux notifications du mécanisme de bordures décrit ci-dessus.

## 7. Bandeau "Prochaine tonte"

En haut de l'onglet Programmation, juste sous les boutons Tester/Sauvegarder : un bandeau bleu indique une estimation détaillée (date et heure quand c'est calculable) de la prochaine tonte, en tenant compte de l'espacement, de l'humidité (avec son délai), de la température, de la condition météo, de la batterie, et des bordures (rattrapage ou jour normal prioritaire sur la tonte classique). Recalculé à chaque chargement de l'onglet et après chaque sauvegarde.

## 8. État des conditions de démarrage

En haut de l'onglet Programmation (visible uniquement quand elle est active) : un tableau détaillant, condition par condition, ce qui est actuellement réuni ou non pour démarrer (espacement, pluie, humidité avec délai, température, condition météo, batterie), plus une ligne dédiée indiquant le **type de la prochaine tonte** (classique / bordures / rattrapage / rattrapage puis classique). Rafraîchi automatiquement à l'ouverture de l'onglet et après chaque sauvegarde, avec un bouton pour le rafraîchir manuellement.

## 9. Widget dashboard

Boutons Activer/Désactiver la programmation, curseurs réglables (marge, espacement, seuil d'humidité), et une info "Prochaine tonte" qui indique non seulement si une tonte est prévue, mais **son type** : tonte classique, bordures, rattrapage de bordures, ou rattrapage de bordures suivi d'une tonte classique. Le nom du robot et son statut (si la commande Statut est liée) s'affichent juste au-dessus — pas besoin de dupliquer l'ensemble des états du robot ici, ils restent sur l'équipement robot d'origine.

## 10. Aperçus en direct (✅/❌)

À côté de chaque champ de commande, un petit indicateur s'affiche automatiquement dès que vous renseignez ou modifiez la valeur :
- ✅ vert : commande trouvée. Pour les commandes **info**, la valeur actuelle est affichée (et vérifiée par rapport aux bornes attendues si applicable — ex: batterie entre 0 et 100). Pour les commandes **action** (Commande pour lancer la tonte, Commande de retour à la maison, Commande pour lancer la tonte des bordures), il confirme juste que la commande existe (impossible d'en afficher une "valeur" sans l'exécuter réellement, ce que RoboProg ne fait jamais pendant un simple aperçu).
- ❌ rouge : commande introuvable, ou valeur hors des bornes attendues.

## 11. Outils de débogage

- **[Débogage] Régler la dernière tonte à hier** : pour tester le déclenchement le jour même sans attendre l'espacement complet.
- **Marquer la tonte d'aujourd'hui comme faite** : à utiliser après une tonte manuelle (hors programmation).
- **Réinitialiser l'anti-doublon des notifications "pas de tonte"** : débloque l'envoi immédiat, sans attendre le lendemain.

## 12. Aucune dépendance externe

RoboProg n'a **strictement aucune dépendance** : ni Python, ni environnement virtuel, ni bibliothèque tierce. Tout repose uniquement sur le système de commandes natif de Jeedom (PHP pur).

## 13. Limitations connues

- RoboProg ne fait aucune vérification sur la CAPACITÉ réelle du robot à exécuter les commandes envoyées, au-delà du seuil de batterie configuré, si une commande Batterie est liée. **Si aucune commande Batterie n'est liée**, RoboProg n'a aucun moyen de savoir qu'une commande de démarrage envoyée n'a en réalité pas été exécutée faute de charge suffisante — il considérera à tort la tonte comme lancée (`last_mow_date` mis à jour), et n'en retentera pas d'autre ce jour-là.
- La détection du "retour à la maison" dépend entièrement de la fiabilité de la commande Statut de l'équipement robot d'origine, et de la justesse de la valeur enregistrée via le bouton dédié. Si le robot change de firmware et que le libellé de statut change, il faut réenregistrer.
- La détection d'une interruption des bordures par la pluie (section 5) repose **uniquement** sur le(s) capteur(s) pluie. Sans eux, RoboProg ne peut pas distinguer un retour normal d'un retour interrompu par la pluie, et relancera la tonte classique dans tous les cas.
