# Diagrama de Gantt - Instruccions d'ús

## Descripció

Aquest és un editor de diagrames de Gantt que permet gestionar projectes, tasques i subtasques d'una manera visual i intuïtiva. Podeu organitzar les tasques en el temps, assignar-les a usuaris, i visualitzar el progrés del projecte en una línia temporal.

## Instal·lació

### Requisits

- Servidor web amb PHP 7.4 o superior
- SQLite3 (inclòs amb PHP)
- Navegador web modern amb suport per JavaScript (Chrome, Firefox, Safari, Edge)

### Pasos d'instal·lació

1. **Descarregar o clonar el projecte**
   ```bash
   git clone <repository-url>
   cd pppgantt
   ```

2. **Configurar el servidor web**
   - Assegureu-vos que el directori del projecte està accessible a través del servidor web
   - Per a desenvolupament local, podeu utilitzar el servidor PHP integrat:
     ```bash
     php -S localhost:8000
     ```

3. **Configurar permisos**
   - Assegureu-vos que el servidor web té permisos d'escriptura al directori per crear la base de dades SQLite (`data.sqlite`)
   ```bash
   chmod 755 .
   chmod 666 data.sqlite  # Si ja existeix
   ```

4. **Configurar la contrasenya d'administrador**
   - Obreu el fitxer `config.php` i canvieu la contrasenya d'administrador per defecte:
     ```php
     'admin_password' => 'la-teva-contrasenya-segura',
     ```

5. **Accedir a l'aplicació**
   - Obreu el navegador i aneu a `http://localhost:8000` (o la URL del vostre servidor)
   - Per accedir a l'àrea d'administració, aneu a `http://localhost:8000/admin.php`
   - La base de dades SQLite es crearà automàticament en la primera execució

### Primera configuració

1. Accediu a `/admin.php` i inicieu sessió amb la contrasenya d'administrador configurada
2. Creeu el vostre primer projecte
3. Assigneu una contrasenya opcional al projecte (per protegir l'accés)
4. Accediu al projecte mitjançant la URL proporcionada (ex: `project.php?slug=nom-del-projecte`)

### Estructura de fitxers

```
pppgantt/
├── index.html          # Pàgina d'inici
├── admin.php           # Àrea d'administració
├── project.php         # Vista de projecte individual
├── api.php             # API REST
├── db.php              # Configuració de base de dades
├── config.php          # Configuració (contrasenya admin)
├── app.js              # Aplicació Vue.js
├── styles.css          # Estils CSS
├── data.sqlite         # Base de dades SQLite (es crea automàticament)
└── README.md           # Aquest fitxer
```

## Funcionalitats principals

### Gestió de projectes
- **Nom del projecte**: Feu clic al nom del projecte per editar-lo (quan esteu en mode edició)
- **Data d'inici**: Establiu la data d'inici del projecte des de la capçalera

### Gestió d'usuaris
- **Afegir usuaris**: Feu clic al botó "+ Add user" per afegir nous usuaris
- **Editar usuari**: Feu clic sobre un usuari per editar-ne el nom i color
- **Eliminar usuari**: Feu clic a la "×" al costat de l'usuari per eliminar-lo (només en mode edició)
- **Assignar colors**: Cada usuari pot tenir un color personalitzat per facilitar la visualització

### Mode edició
- **Activar/Desactivar**: Feu clic al botó "Edit" a la capçalera per activar o desactivar el mode edició
- Quan el mode edició està actiu, podeu modificar tots els elements del diagrama
- Quan està desactivat, només podeu visualitzar el diagrama

### Tasques principals (Files)
- **Afegir tasca**: Feu clic al botó "+" a la capçalera per afegir una nova tasca principal
- **Editar nom**: Feu clic al nom de la tasca per editar-lo (en mode edició)
- **Reordenar files**: Arrossegueu l'icona de sis punts (⋮⋮) a l'esquerra del nom de la tasca per reordenar les files
- **Eliminar tasca**: Feu clic al botó "×" a la dreta del nom de la tasca per eliminar-la

### Subtasques
- **Afegir subtasca**: Feu clic al botó "+" a la dreta de cada fila o al final de la línia temporal
- **Editar subtasca**: Feu clic sobre la barra de la subtasca per seleccionar-la i editar-la
- **Canviar nom**: Quan una subtasca està seleccionada, podeu editar el seu nom directament
- **Assignar usuari**: Seleccioneu un usuari del menú desplegable quan la subtasca està seleccionada
- **Modificar duració**: Canvieu el nombre de dies a l'input numèric quan la subtasca està seleccionada
- **Redimensionar**: Arrossegueu l'extrem dret de la barra de la subtasca per canviar-ne la duració
- **Eliminar subtasca**: Feu clic al botó "×" quan la subtasca està seleccionada

### Reordenació de subtasques
- **Reordenar dins d'una fila**: Arrossegueu l'icona de sis punts (⋮⋮) dins de la barra de la subtasca (a l'esquerra) per reordenar les subtasques dins de la mateixa fila
- **Moure totes les subtasques horitzontalment**: Utilitzeu la fletxa doble (⇄) a l'esquerra de la primera subtasca d'una fila per moure totes les subtasques de la fila horitzontalment al mateix temps

### Visualització
- **Línies de setmana**: Es mostren línies verticals al fons per indicar l'inici de cada setmana
- **Línia de finalització**: Una línia vertical més gruixuda amb una bandera mostra la data de finalització del projecte (basada en la data de final de la darrera subtasca)
- **Colors per usuari**: Cada subtasca es mostra amb el color de l'usuari assignat
- **Inicials de l'usuari**: Quan una subtasca té un usuari assignat i no està en edició, es mostra la inicial de l'usuari a la barra
- **Títol en passar el ratolí**: Passeu el ratolí sobre una subtasca per veure el seu nom complet si està truncat

## Com utilitzar l'aplicació

### Pas 1: Configurar el projecte
1. Activeu el mode edició fent clic a "Edit"
2. Feu clic al nom del projecte per canviar-lo
3. Establiu la data d'inici del projecte

### Pas 2: Afegir usuaris
1. Feu clic a "+ Add user"
2. Feu clic sobre l'usuari per editar-ne el nom
3. Seleccioneu un color per a cada usuari

### Pas 3: Crear tasques
1. Feu clic al botó "+" a la capçalera per afegir una tasca principal
2. Editeu el nom de la tasca fent clic sobre ell
3. Feu clic al botó "+" a la dreta de la fila per afegir subtasques

### Pas 4: Configurar subtasques
1. Feu clic sobre una subtasca per seleccionar-la
2. Editeu el nom, assigneu un usuari i estableix la duració
3. Arrossegueu l'extrem dret per ajustar la duració visualment

### Pas 5: Organitzar el calendari
1. Utilitzeu la fletxa doble (⇄) a l'esquerra de la primera subtasca per moure totes les subtasques d'una fila
2. Reordeneu les subtasques dins d'una fila arrossegant l'icona de sis punts
3. Reordeneu les files arrossegant l'icona de sis punts a l'esquerra del nom de la tasca

### Pas 6: Finalitzar
1. Desactiveu el mode edició fent clic a "Done" per visualitzar el diagrama final
2. La bandera al final mostra la data de finalització del projecte

## Consells

- **Mode edició**: Recordeu que la majoria de les funcions només estan disponibles quan el mode edició està actiu
- **Arrossegament**: Quan arrossegueu subtasques o files per reordenar-les, el subtasques no entrarà en mode edició després d'alliberar
- **Visualització**: Les línies de setmana i la bandera de finalització ajuden a visualitzar millor el temps del projecte
- **Colors**: Assigneu colors diferents als usuaris per identificar ràpidament qui treballa en cada subtasca
- **Duració**: La duració de les subtasques es pot modificar tant numèricament com visualment arrossegant l'extrem de la barra

## Requisits tècnics

- Un servidor web amb PHP i SQLite
- Navegador web modern amb suport per JavaScript

## Notes

- Totes les dades es desen automàticament en una base de dades SQLite local
- La visualització s'ajusta automàticament segons les dates de les subtasques
- El diagrama mostra setmanes i mesos per facilitar la visualització temporal

