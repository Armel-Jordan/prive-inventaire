# Prise Inventaire - Site Marketing

Site web marketing pour **Prise Inventaire**, une solution complète de gestion d'inventaire conçue pour les entreprises africaines.

## 🚀 Technologies

- **React 19** - Framework UI
- **TypeScript** - Typage statique
- **Vite** - Build tool rapide
- **React Router DOM** - Routing SPA
- **Lucide React** - Icônes
- **CSS pur** - Pas de TailwindCSS, styles personnalisés

## 📁 Structure du projet

```
src/
├── components/          # Composants réutilisables
│   ├── Navbar.tsx       # Barre de navigation avec sélecteur de langue
│   ├── Navbar.css
│   ├── Footer.tsx       # Pied de page avec liens
│   └── Footer.css
├── pages/               # Pages de l'application
│   ├── HomePage.tsx     # Page d'accueil
│   ├── SolutionsPage.tsx # Nos solutions (Mobile, Web, Client)
│   ├── VisionPage.tsx   # Notre vision et présence en Afrique
│   ├── TarifsPage.tsx   # Plans tarifaires et FAQ
│   ├── FormationsPage.tsx # Formations et certifications
│   ├── ContactPage.tsx  # Formulaire de contact
│   ├── LegalPage.tsx    # Mentions légales
│   ├── PrivacyPage.tsx  # Politique de confidentialité
│   ├── TermsPage.tsx    # Conditions générales de vente
│   └── *.css            # Styles associés
├── i18n/                # Internationalisation (FR/EN)
│   ├── translations.ts  # Toutes les traductions
│   ├── LanguageContext.tsx # Provider de langue
│   ├── context.ts       # Contexte React
│   ├── useLanguage.ts   # Hook personnalisé
│   └── index.ts         # Exports
├── App.tsx              # Configuration des routes
├── main.tsx             # Point d'entrée
└── index.css            # Styles globaux et variables CSS
```

## 🌍 Internationalisation (i18n)

Le site supporte **Français** et **Anglais** :

- Sélecteur de langue dans la Navbar (icône Globe)
- Persistance de la préférence dans `localStorage`
- Attribut `lang` du HTML mis à jour automatiquement

### Utilisation dans les composants

```tsx
import { useLanguage } from '../i18n';

function MonComposant() {
  const { t, language, setLanguage } = useLanguage();
  
  return (
    <div>
      <h1>{t.home.title}</h1>
      <button onClick={() => setLanguage(language === 'fr' ? 'en' : 'fr')}>
        {language.toUpperCase()}
      </button>
    </div>
  );
}
```

## 📄 Pages

| Route | Page | Description |
|-------|------|-------------|
| `/` | HomePage | Accueil avec hero, stats, solutions, mission Afrique |
| `/solutions` | SolutionsPage | Détail des 3 applications (Mobile, Web, Client) |
| `/vision` | VisionPage | Vision, mission, valeurs, présence en Afrique |
| `/tarifs` | TarifsPage | Plans Starter, Business, Enterprise + FAQ |
| `/formations` | FormationsPage | Formations en ligne, à distance, sur site |
| `/contact` | ContactPage | Formulaire de contact |
| `/mentions-legales` | LegalPage | Mentions légales |
| `/confidentialite` | PrivacyPage | Politique de confidentialité |
| `/cgv` | TermsPage | Conditions générales de vente |

## 🎨 Variables CSS

Les couleurs et styles sont définis dans `src/index.css` :

```css
:root {
  --primary: #2563eb;      /* Bleu principal */
  --primary-dark: #1d4ed8;
  --secondary: #10b981;    /* Vert */
  --accent: #f59e0b;       /* Orange */
  --dark: #1e293b;
  --gray: #64748b;
  --gray-light: #94a3b8;
  --gray-lighter: #f1f5f9;
  --white: #ffffff;
}
```

## 🛠️ Installation

```bash
# Installer les dépendances
npm install

# Lancer en développement
npm run dev

# Build pour production
npm run build

# Preview du build
npm run preview
```

## 📦 Dépendances principales

```json
{
  "react": "^19.0.0",
  "react-dom": "^19.0.0",
  "react-router-dom": "^7.x",
  "lucide-react": "^0.x"
}
```

## 🌐 Déploiement

Le site est configuré pour être déployé sur **Vercel** ou **Netlify**.

```bash
# Build
npm run build

# Le dossier dist/ contient les fichiers statiques
```

## 📝 Ajouter une nouvelle page

1. Créer le fichier `src/pages/MaPage.tsx`
2. Créer le fichier CSS `src/pages/MaPage.css`
3. Ajouter les traductions dans `src/i18n/translations.ts` (FR et EN)
4. Ajouter la route dans `src/App.tsx`

## 🔧 Ajouter des traductions

1. Ouvrir `src/i18n/translations.ts`
2. Ajouter les clés dans l'objet `fr` 
3. Ajouter les mêmes clés avec traductions dans l'objet `en`
4. Utiliser avec `t.maSection.maCle` dans les composants

---

**Prise Inventaire** - La solution de gestion d'inventaire pour l'Afrique 🌍
