# Twisto/Timeo Real-time API

## Introduction

Le réseau Twisto est le réseau de bus et tram de la ville de Caen, géré par Viacités. Malheureusement, ce syndicat ne propose pas d'API pour accéder à ses services qui se vantent pourtant d'être disponibles sur un maximum d'appareils possible.

Cette API non-officielle comble partiellement ce manque en vous permettant, notamment, d'obtenir en temps réel et au format JSON les horaires des prochains bus Twisto, de la ville de Caen.

Vous pourrez également lister les lignes de bus, directions et arrêts disponibles.

## Fonctionnement

L'API est écrite en PHP et repose sur le système Timeo de Actigraph, disponible sur les sites officiels de Twisto et les applications mobiles.

## Utilisation

Appelez le script `twisto-api.php` avec différentes variables GET en fonction du résultat souhaité.  
Le résultat est renvoyé sous forme d'un string JSON.

### Lister les lignes de bus/tram

	twisto-api.php?func=getLines

*Exemple de résultat* :

```json
[
	{"id": "TRAM", "name": "TRAM"},
	{"id": "1", "name": "Lianes 1"},
	{"id": "2", "name": "Lianes 2"},
	{"id": "3", "name": "Lianes 3"},
	{"id": "4", "name": "Lianes 4"},
	{"id": "5", "name": "Ligne 5"},
	{"id": "6", "name": "Ligne 6"},
	{"id": "7", "name": "Ligne 7"},
	{"id": "8", "name": "Ligne 8"},
	{"id": "9", "name": "Ligne 9"},
	...
	{"id": "NUIT", "name": "Ligne Noctibus"}
]
```

L'identifiant obtenu pour chaque ligne pourra servir pour les autres fonctions du script.

----------------------------------------

### Lister les directions pour une ligne donnée

	twisto-api.php?func=getDirections&line=XX

...où XX est l'identifiant de la ligne.

*Exemple de requête* :

	twisto-api.php?func=getDirections&line=11

*Exemple de résultat* :

```json
[
	{"id": "A", "name": "Cuverville mairie"},
	{"id": "R", "name": "Bretteville l'enclos"}
]
```

L'identifiant est A pour "aller", ou R pour "retour".

----------------------------------------

### Lister les arrêts pour une ligne et une direction données

	twisto-api.php?func=getStops&line=XX&direction=A|R

...où XX est l'identifiant de la ligne, et la direction est A ou R.

*Exemple de requête* :

	twisto-api.php?func=getStops&line=11&direction=A

*Exemple de résultat* :

```json
[
	{"id": "5421", 	"name": "50 acres"},
	{"id": "11", 	"name": "Bibliotheque"},
	{"id": "4072", 	"name": "Bois claquet"},
	{"id": "4082", 	"name": "Briere"},
	{"id": "3532", 	"name": "Carrefour de la liberte"},
	{"id": "4112",	"name": "Charmettes"},
	{"id": "4122", 	"name": "Clair soleil"},
	{"id": "511", 	"name": "Creux au renard"},
	{"id": "1892", 	"name": "Demi-lune"},
	{"id": "121", 	"name": "Demoge"},
	{"id": "2052", 	"name": "Edmond rostand"},
	{"id": "5461", 	"name": "Eglise de bretteville"},
	...
	{"id": "4392", 	"name": "Vallee barrey"}
]
```

----------------------------------------

### Lister les prochains bus pour UNE ligne, UNE direction et UN arrêt

	twisto-api.php?func=getSchedule&line=XX&direction=A|R&stop=XXXX

...où XX est l'identifiant de la ligne, la direction est A ou R et XXXX est l'identifiant de l'arrêt.

*Exemple de requête* :

	twisto-api.php?func=getSchedule&line=11&direction=A&stop=5421

*Exemple de résultat* :

```json
[
	{
		"line": "Ligne 11",
		"direction": "Cuverville mairie",
		"stop": "Arrêt 50 acres",
		"next": [
			"Dans 15 minutes", 
			"Dans 35 minutes"
		]
	}
]
```

----------------------------------------

### Lister les prochains bus pour plusieurs lignes/directions/arrêts à la fois

Pour cela, on envoie directement un cookie (le même que celui utilisé par le système officiel).  
Le cookie est de la forme `ARRÊT|LIGNE|DIRECTION;ARRÊT|LIGNE|DIRECTION;...`.

*Exemple de requête (non-URLencoded)* :

	twisto-api.php?func=getSchedule&data=5421|11|A;12|11|R;251|3|A

*Exemple de requête (URLencoded)* :

	twisto-api.php?func=getSchedule&data=5421%7C11%7CA%3B12%7C11%7CR%3B251%7C3%7CA

*Exemple de résultat* :

```json
[
	{
		"line": "Ligne 11",
		"direction": "Cuverville mairie",
		"stop": "Arrêt 50 acres",
		"next": [
			"Dans 15 minutes", 
			"Dans 35 minutes"
		]
	}, {
		"line": "Ligne 11",
		"direction": "Bretteville l'enclos",
		"stop": "Arrêt Bibliotheque",
		"next": [
			"Dans 3 minutes", 
			"Dans 25 minutes"
		]
	}, {
		"line": "Lianes 3",
		"direction": "Herouville st-clair",
		"stop": "Arrêt Bicoquet",
		"next": [
			"Passage imminent",
			"À 0 H 25"
		]
	}
]
```

----------------------------------------

### Gestion des erreurs

Si une erreur survient durant l'exécution du script, un message d'erreur devrait être inclus dans le JSON retourné.

*Exemple* :

```json
{"error": "Could not resolve host: dev.actigraph.fr; nodename nor servname provided, or not known"}
```

Le script retournera une erreur 400 si des paramètres sont manquants ou non reconnus.