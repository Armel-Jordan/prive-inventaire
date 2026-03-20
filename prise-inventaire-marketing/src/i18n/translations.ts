export type Language = 'fr' | 'en';

export const translations = {
  fr: {
    // Navbar
    nav: {
      home: 'Accueil',
      solutions: 'Nos Solutions',
      vision: 'Notre Vision',
      pricing: 'Tarifs',
      training: 'Formations',
      contact: 'Contact',
      demo: 'Demander une démo'
    },
    // Footer
    footer: {
      description: 'La solution complète de gestion d\'inventaire pour les entreprises africaines. Simplifiez vos opérations, optimisez vos stocks.',
      solutions: 'Solutions',
      mobileApp: 'Application Mobile',
      webDashboard: 'Dashboard Web',
      clientPortal: 'Portail Client',
      ourPacks: 'Nos Packs',
      company: 'Entreprise',
      ourVision: 'Notre Vision',
      training: 'Formations',
      contact: 'Contact',
      careers: 'Carrières',
      contactTitle: 'Contact',
      copyright: '© {year} Prise Inventaire. Tous droits réservés.',
      legal: 'Mentions légales',
      privacy: 'Politique de confidentialité',
      terms: 'CGV'
    },
    // Home Page
    home: {
      badge: '🚀 Nouveau : Portail Client bientôt disponible',
      title: 'Gérez votre inventaire',
      titleHighlight: 'simplement',
      subtitle: 'La solution complète de gestion d\'inventaire conçue pour les entreprises africaines. Application mobile, dashboard web et portail client dans un seul écosystème.',
      ctaDemo: 'Demander une démo',
      ctaDiscover: 'Découvrir nos solutions',
      stats: {
        companies: 'Entreprises',
        products: 'Produits gérés',
        uptime: 'Disponibilité',
        support: 'Support'
      },
      ecosystem: 'Un écosystème complet',
      ecosystemSubtitle: 'Trois applications interconnectées pour couvrir tous vos besoins en gestion d\'inventaire',
      solutions: {
        mobile: {
          title: 'Application Mobile',
          description: 'Scanner QR/Barcode, inventaire terrain, mode hors-ligne. Idéal pour vos équipes sur le terrain.'
        },
        web: {
          title: 'Dashboard Web',
          description: 'Tableau de bord complet pour les managers. Rapports, statistiques, gestion des équipes.'
        },
        client: {
          title: 'Portail Client',
          description: 'Vos clients passent leurs commandes en ligne. Suivi en temps réel des livraisons.',
          badge: 'Bientôt'
        }
      },
      learnMore: 'En savoir plus',
      whyChoose: 'Pourquoi choisir Prise Inventaire ?',
      whyChooseSubtitle: 'Des fonctionnalités pensées pour les réalités du terrain africain',
      features: {
        realtime: {
          title: 'Suivi en temps réel',
          description: 'Visualisez vos stocks instantanément depuis n\'importe où'
        },
        security: {
          title: 'Sécurité maximale',
          description: 'Vos données sont protégées avec un chiffrement de niveau entreprise'
        },
        offline: {
          title: 'Mode hors-ligne',
          description: 'Continuez à travailler même sans connexion internet'
        },
        multisite: {
          title: 'Multi-sites',
          description: 'Gérez plusieurs entrepôts et points de vente facilement'
        }
      },
      africaMission: '🌍 Notre Mission',
      africaTitle: 'Conçu pour l\'Afrique',
      africaDescription: 'Nous comprenons les défis uniques des entreprises africaines : connexion internet instable, équipes sur le terrain, gestion multi-sites. Notre solution est pensée pour fonctionner dans ces conditions.',
      africaFeatures: {
        offline: 'Mode hors-ligne complet',
        simple: 'Interface simple et intuitive',
        french: 'Support en français',
        training: 'Formations sur site disponibles'
      },
      discoverVision: 'Découvrir notre vision',
      countries: {
        cameroon: {
          name: 'Cameroun',
          description: 'Siège social à Douala'
        },
        ivoryCoast: {
          name: 'Côte d\'Ivoire',
          description: 'Expansion en cours'
        },
        westAfrica: {
          name: 'Afrique de l\'Ouest',
          description: 'Prochainement'
        }
      },
      ctaTitle: 'Prêt à transformer votre gestion d\'inventaire ?',
      ctaSubtitle: 'Rejoignez les entreprises qui font confiance à Prise Inventaire',
      ctaDemoFree: 'Demander une démo gratuite',
      ctaPricing: 'Voir les tarifs'
    },
    // Solutions Page
    solutions: {
      title: 'Nos Solutions',
      subtitle: 'Un écosystème complet pour digitaliser votre gestion d\'inventaire',
      mobile: {
        badge: 'Application Mobile',
        title: 'Inventaire sur le terrain',
        description: 'Équipez vos équipes d\'une application mobile puissante pour gérer l\'inventaire directement sur le terrain. Scanner, compter, relocaliser — tout devient simple et rapide.',
        features: {
          scan: 'Scanner QR Code et codes-barres',
          offline: 'Mode hors-ligne complet',
          sync: 'Synchronisation automatique',
          multiUser: 'Gestion multi-employés',
          notifications: 'Notifications en temps réel',
          history: 'Historique des scans'
        },
        cta: 'Télécharger l\'APK'
      },
      web: {
        badge: 'Dashboard Web',
        title: 'Pilotage centralisé',
        description: 'Un tableau de bord complet pour les managers et administrateurs. Visualisez vos stocks, analysez les tendances, gérez vos équipes et prenez des décisions éclairées.',
        features: {
          dashboard: 'Tableaux de bord interactifs',
          reports: 'Rapports détaillés et exports',
          roles: 'Gestion des rôles et permissions',
          alerts: 'Alertes de stock configurables',
          suppliers: 'Gestion fournisseurs et commandes',
          billing: 'Facturation et comptabilité'
        },
        cta: 'Demander un accès'
      },
      client: {
        badge: 'Portail Client',
        badgeSoon: 'Bientôt',
        title: 'Vos clients commandent en ligne',
        description: 'Offrez à vos clients un portail moderne pour passer leurs commandes, suivre leurs livraisons et consulter leur historique. Simplifiez votre relation client.',
        features: {
          orders: 'Passage de commandes en ligne',
          tracking: 'Suivi des commandes en temps réel',
          history: 'Historique et factures',
          delivery: 'Suivi des livraisons',
          payments: 'Paiements sécurisés',
          notifications: 'Notifications de statut'
        },
        cta: 'Être notifié du lancement'
      },
      integration: {
        title: 'Tout est connecté',
        subtitle: 'Nos trois applications communiquent en temps réel pour une gestion fluide',
        mobile: 'Mobile',
        web: 'Web',
        sync: 'Synchronisation temps réel'
      },
      cta: {
        title: 'Prêt à découvrir nos solutions ?',
        subtitle: 'Demandez une démonstration personnalisée gratuite',
        button: 'Demander une démo'
      }
    },
    // Vision Page
    vision: {
      badge: '🌍 Notre Vision',
      title: 'Digitaliser l\'Afrique,',
      titleLine2: 'une entreprise à la fois',
      subtitle: 'Nous croyons que chaque entreprise africaine mérite des outils modernes pour gérer son activité efficacement.',
      mission: {
        title: 'Notre Mission',
        lead: 'Fournir aux entreprises africaines des solutions de gestion d\'inventaire <strong>simples, fiables et abordables</strong>, conçues pour fonctionner dans les conditions réelles du terrain.',
        p1: 'Nous avons constaté que la plupart des solutions existantes sont soit trop complexes, soit trop coûteuses, soit inadaptées aux réalités africaines (connexion internet instable, équipes peu formées au digital, etc.).',
        p2: 'C\'est pourquoi nous avons créé <strong>Prise Inventaire</strong> : une solution pensée par des Africains, pour des Africains, avec une compréhension profonde des défis locaux.'
      },
      stats: {
        year: 'Année de création',
        countries: 'Pays ciblés',
        madeIn: 'Made in Africa',
        ambition: 'Ambition'
      },
      values: {
        title: 'Nos Valeurs',
        subtitle: 'Les principes qui guident chacune de nos décisions',
        innovation: {
          title: 'Innovation',
          description: 'Nous développons des solutions adaptées aux réalités africaines, pas des copies de produits occidentaux.'
        },
        proximity: {
          title: 'Proximité',
          description: 'Support local, formations sur site, accompagnement personnalisé pour chaque client.'
        },
        efficiency: {
          title: 'Efficacité',
          description: 'Des outils simples et performants qui répondent aux vrais besoins des entreprises.'
        },
        accessibility: {
          title: 'Accessibilité',
          description: 'Des tarifs adaptés aux PME africaines, sans compromis sur la qualité.'
        }
      },
      presence: {
        title: 'Notre Présence en Afrique',
        subtitle: 'Nous construisons un réseau solide à travers le continent',
        active: 'Actif',
        expansion: 'Expansion',
        soon: 'Prochainement',
        cameroon: {
          description: 'Notre siège social est basé à Douala. Nous accompagnons déjà de nombreuses entreprises camerounaises dans leur transformation digitale.'
        },
        ivoryCoast: {
          description: 'Nous étendons nos services en Côte d\'Ivoire pour accompagner les entreprises ivoiriennes dans leur gestion d\'inventaire.'
        },
        senegal: {
          description: 'Le Sénégal fait partie de notre plan d\'expansion pour couvrir l\'Afrique de l\'Ouest francophone.'
        },
        gabon: {
          description: 'Nous préparons notre entrée sur le marché gabonais pour servir les entreprises de la sous-région.'
        }
      },
      whyAfrica: {
        title: 'Pourquoi l\'Afrique ?',
        description: 'L\'Afrique est le continent de demain. Avec une population jeune, une croissance économique soutenue et une adoption rapide du mobile, les opportunités sont immenses.',
        stats: {
          population: 'd\'habitants d\'ici 2030',
          youth: 'de la population a moins de 25 ans',
          growth: 'de croissance économique moyenne',
          mobile: 'de pénétration mobile'
        },
        conclusion: 'Nous voulons être acteurs de cette transformation en fournissant les outils qui permettront aux entreprises africaines de prospérer.'
      },
      cta: {
        title: 'Rejoignez l\'aventure',
        subtitle: 'Faites partie des entreprises qui transforment l\'Afrique',
        contact: 'Nous contacter',
        offers: 'Voir nos offres'
      }
    },
    // Pricing Page
    pricing: {
      title: 'Des tarifs adaptés à votre entreprise',
      subtitle: 'Choisissez le plan qui correspond à vos besoins. Évoluez à votre rythme.',
      popular: 'Le plus populaire',
      plans: {
        starter: {
          name: 'Starter',
          description: 'Pour les petites entreprises qui démarrent',
          cta: 'Commencer'
        },
        business: {
          name: 'Business',
          description: 'Pour les PME en croissance',
          cta: 'Choisir Business'
        },
        enterprise: {
          name: 'Enterprise',
          description: 'Pour les grandes entreprises',
          price: 'Sur mesure',
          cta: 'Nous contacter'
        }
      },
      features: {
        mobileUsers: 'Application mobile ({count} utilisateurs)',
        mobileUnlimited: 'Utilisateurs illimités',
        webAdmins: 'Dashboard web ({count} admins)',
        webUnlimited: 'Admins illimités',
        products: 'Jusqu\'à {count} produits',
        productsUnlimited: 'Produits illimités',
        sectors: '{count} secteur/entrepôt',
        sectorsMultiple: 'Jusqu\'à {count} secteurs/entrepôts',
        sectorsUnlimited: 'Sites illimités',
        supportEmail: 'Support par email',
        supportPriority: 'Support prioritaire',
        supportDedicated: 'Support dédié 24/7',
        offline: 'Mode hors-ligne',
        reportsBasic: 'Rapports basiques',
        reportsAdvanced: 'Rapports avancés',
        reportsCustom: 'Rapports personnalisés',
        multisite: 'Multi-sites',
        api: 'API personnalisée',
        trainingNone: 'Formation sur site',
        trainingOne: 'Formation sur site (1 session)',
        trainingUnlimited: 'Formations illimitées'
      },
      included: {
        title: 'Inclus dans tous les plans',
        mobile: {
          title: 'Application Mobile',
          description: 'Android avec mode hors-ligne'
        },
        web: {
          title: 'Dashboard Web',
          description: 'Accessible depuis tout navigateur'
        },
        updates: {
          title: 'Mises à jour',
          description: 'Nouvelles fonctionnalités gratuites'
        },
        support: {
          title: 'Support',
          description: 'Équipe francophone réactive'
        }
      },
      faq: {
        title: 'Questions fréquentes',
        q1: {
          question: 'Puis-je changer de plan à tout moment ?',
          answer: 'Oui, vous pouvez upgrader ou downgrader votre plan à tout moment. Les changements prennent effet au prochain cycle de facturation.'
        },
        q2: {
          question: 'Y a-t-il une période d\'essai ?',
          answer: 'Oui, nous offrons 14 jours d\'essai gratuit sur tous nos plans, sans engagement et sans carte bancaire.'
        },
        q3: {
          question: 'Comment fonctionne la facturation ?',
          answer: 'La facturation est mensuelle. Nous acceptons Mobile Money (Orange Money, MTN MoMo), les virements bancaires et les cartes de crédit.'
        },
        q4: {
          question: 'Que se passe-t-il si je dépasse mes limites ?',
          answer: 'Nous vous prévenons à l\'avance. Vous pouvez soit upgrader votre plan, soit nous contacter pour une solution personnalisée.'
        },
        q5: {
          question: 'Les formations sont-elles incluses ?',
          answer: 'Une formation en ligne est incluse dans tous les plans. Les formations sur site sont disponibles dans les plans Business et Enterprise.'
        },
        q6: {
          question: 'Proposez-vous des réductions annuelles ?',
          answer: 'Oui ! En payant annuellement, vous bénéficiez de 2 mois gratuits (soit ~17% de réduction).'
        }
      },
      cta: {
        title: 'Besoin d\'un plan personnalisé ?',
        subtitle: 'Contactez-nous pour discuter de vos besoins spécifiques',
        button: 'Parler à un conseiller'
      }
    },
    // Training Page
    training: {
      title: 'Formations Prise Inventaire',
      subtitle: 'Maîtrisez notre solution et maximisez votre productivité',
      formulas: {
        title: 'Nos formules de formation',
        subtitle: 'Choisissez le format qui convient le mieux à votre équipe',
        online: {
          title: 'Formation en ligne',
          duration: '2 heures',
          price: 'Gratuit',
          description: 'Formation vidéo complète pour maîtriser les bases de Prise Inventaire.',
          features: ['Accès illimité aux vidéos', 'Quiz de validation', 'Certificat de complétion', 'Support par email'],
          cta: 'Accéder',
          included: 'Inclus dans tous les plans'
        },
        remote: {
          title: 'Formation à distance',
          duration: '1 journée',
          price: '50 000 FCFA',
          description: 'Session de formation en visioconférence avec un formateur dédié.',
          features: ['Formation personnalisée', 'Questions/réponses en direct', 'Exercices pratiques', 'Support 30 jours inclus'],
          cta: 'Réserver'
        },
        onsite: {
          title: 'Formation sur site',
          duration: '1-2 jours',
          price: '150 000 FCFA',
          description: 'Un formateur se déplace dans vos locaux pour former vos équipes.',
          features: ['Formation dans vos locaux', 'Adaptation à vos processus', 'Formation de formateurs', 'Support 60 jours inclus'],
          cta: 'Réserver'
        }
      },
      program: {
        title: 'Programme de formation',
        subtitle: 'Un parcours complet pour maîtriser tous les aspects de la solution',
        modules: {
          m1: { title: 'Prise en main', topics: ['Installation de l\'application', 'Configuration initiale', 'Création des utilisateurs'] },
          m2: { title: 'Gestion des produits', topics: ['Ajout de produits', 'Catégorisation', 'Codes-barres et QR codes'] },
          m3: { title: 'Inventaire terrain', topics: ['Utilisation du scanner', 'Mode hors-ligne', 'Synchronisation'] },
          m4: { title: 'Dashboard & Rapports', topics: ['Lecture des statistiques', 'Génération de rapports', 'Alertes de stock'] },
          m5: { title: 'Gestion avancée', topics: ['Multi-sites', 'Rôles et permissions', 'Intégrations'] }
        }
      },
      certification: {
        title: 'Certification Prise Inventaire',
        description: 'À l\'issue de chaque formation, recevez un certificat attestant de vos compétences. Ce certificat est reconnu par toutes les entreprises utilisant notre solution.',
        features: ['Certificat numérique vérifiable', 'Validité permanente', 'Badge LinkedIn disponible'],
        certTitle: 'Certificat de Formation',
        certSubtitle: 'Formation Complète'
      },
      testimonials: {
        title: 'Ce qu\'en disent nos clients',
        t1: {
          quote: 'La formation sur site a permis à notre équipe de 15 personnes d\'être opérationnelle en 2 jours. Excellent investissement !',
          author: 'Marie K.',
          company: 'Supermarché Le Bon Prix, Douala'
        },
        t2: {
          quote: 'Les vidéos en ligne sont très bien faites. J\'ai pu former mes employés moi-même grâce à ces ressources.',
          author: 'Jean-Paul M.',
          company: 'Quincaillerie Moderne, Yaoundé'
        }
      },
      sessions: {
        title: 'Prochaines sessions',
        description: 'Nous organisons régulièrement des sessions de formation collectives à Douala et Yaoundé. Places limitées !',
        register: 'S\'inscrire',
        complete: 'Formation complète',
        online: 'Formation en ligne',
        videoconference: 'Visioconférence'
      },
      cta: {
        title: 'Besoin d\'une formation personnalisée ?',
        subtitle: 'Contactez-nous pour organiser une session adaptée à vos besoins',
        button: 'Demander un devis'
      }
    },
    // Contact Page
    contact: {
      title: 'Contactez-nous',
      subtitle: 'Notre équipe est là pour répondre à toutes vos questions',
      form: {
        title: 'Envoyez-nous un message',
        name: 'Nom complet',
        namePlaceholder: 'Votre nom',
        email: 'Email',
        emailPlaceholder: 'votre@email.com',
        phone: 'Téléphone',
        phonePlaceholder: '+237 6XX XXX XXX',
        company: 'Entreprise',
        companyPlaceholder: 'Nom de votre entreprise',
        subject: 'Sujet',
        subjects: {
          demo: 'Demande de démonstration',
          pricing: 'Question sur les tarifs',
          training: 'Formation',
          support: 'Support technique',
          partnership: 'Partenariat',
          other: 'Autre'
        },
        message: 'Message',
        messagePlaceholder: 'Décrivez votre besoin...',
        submit: 'Envoyer le message',
        success: {
          title: 'Message envoyé !',
          description: 'Merci pour votre message. Notre équipe vous répondra dans les plus brefs délais (généralement sous 24h).',
          another: 'Envoyer un autre message'
        }
      },
      info: {
        title: 'Nos coordonnées',
        description: 'N\'hésitez pas à nous contacter par le moyen qui vous convient le mieux. Nous sommes disponibles pour répondre à vos questions.',
        email: 'Email',
        phone: 'Téléphone',
        address: 'Adresse',
        hours: 'Horaires',
        hoursValue: 'Lun-Ven: 8h-18h'
      },
      social: {
        title: 'Suivez-nous',
        description: 'Restez informé de nos actualités et nouveautés'
      }
    }
  },
  en: {
    // Navbar
    nav: {
      home: 'Home',
      solutions: 'Our Solutions',
      vision: 'Our Vision',
      pricing: 'Pricing',
      training: 'Training',
      contact: 'Contact',
      demo: 'Request a demo'
    },
    // Footer
    footer: {
      description: 'The complete inventory management solution for African businesses. Simplify your operations, optimize your stock.',
      solutions: 'Solutions',
      mobileApp: 'Mobile App',
      webDashboard: 'Web Dashboard',
      clientPortal: 'Client Portal',
      ourPacks: 'Our Packs',
      company: 'Company',
      ourVision: 'Our Vision',
      training: 'Training',
      contact: 'Contact',
      careers: 'Careers',
      contactTitle: 'Contact',
      copyright: '© {year} Prise Inventaire. All rights reserved.',
      legal: 'Legal Notice',
      privacy: 'Privacy Policy',
      terms: 'Terms of Service'
    },
    // Home Page
    home: {
      badge: '🚀 New: Client Portal coming soon',
      title: 'Manage your inventory',
      titleHighlight: 'simply',
      subtitle: 'The complete inventory management solution designed for African businesses. Mobile app, web dashboard and client portal in one ecosystem.',
      ctaDemo: 'Request a demo',
      ctaDiscover: 'Discover our solutions',
      stats: {
        companies: 'Companies',
        products: 'Products managed',
        uptime: 'Uptime',
        support: 'Support'
      },
      ecosystem: 'A complete ecosystem',
      ecosystemSubtitle: 'Three interconnected applications to cover all your inventory management needs',
      solutions: {
        mobile: {
          title: 'Mobile App',
          description: 'QR/Barcode scanner, field inventory, offline mode. Ideal for your field teams.'
        },
        web: {
          title: 'Web Dashboard',
          description: 'Complete dashboard for managers. Reports, statistics, team management.'
        },
        client: {
          title: 'Client Portal',
          description: 'Your clients place orders online. Real-time delivery tracking.',
          badge: 'Soon'
        }
      },
      learnMore: 'Learn more',
      whyChoose: 'Why choose Prise Inventaire?',
      whyChooseSubtitle: 'Features designed for African field realities',
      features: {
        realtime: {
          title: 'Real-time tracking',
          description: 'View your stock instantly from anywhere'
        },
        security: {
          title: 'Maximum security',
          description: 'Your data is protected with enterprise-level encryption'
        },
        offline: {
          title: 'Offline mode',
          description: 'Keep working even without internet connection'
        },
        multisite: {
          title: 'Multi-site',
          description: 'Easily manage multiple warehouses and points of sale'
        }
      },
      africaMission: '🌍 Our Mission',
      africaTitle: 'Designed for Africa',
      africaDescription: 'We understand the unique challenges of African businesses: unstable internet connection, field teams, multi-site management. Our solution is designed to work in these conditions.',
      africaFeatures: {
        offline: 'Complete offline mode',
        simple: 'Simple and intuitive interface',
        french: 'French support',
        training: 'On-site training available'
      },
      discoverVision: 'Discover our vision',
      countries: {
        cameroon: {
          name: 'Cameroon',
          description: 'Headquarters in Douala'
        },
        ivoryCoast: {
          name: 'Ivory Coast',
          description: 'Expansion in progress'
        },
        westAfrica: {
          name: 'West Africa',
          description: 'Coming soon'
        }
      },
      ctaTitle: 'Ready to transform your inventory management?',
      ctaSubtitle: 'Join the companies that trust Prise Inventaire',
      ctaDemoFree: 'Request a free demo',
      ctaPricing: 'View pricing'
    },
    // Solutions Page
    solutions: {
      title: 'Our Solutions',
      subtitle: 'A complete ecosystem to digitize your inventory management',
      mobile: {
        badge: 'Mobile App',
        title: 'Field inventory',
        description: 'Equip your teams with a powerful mobile app to manage inventory directly in the field. Scan, count, relocate — everything becomes simple and fast.',
        features: {
          scan: 'Scan QR codes and barcodes',
          offline: 'Complete offline mode',
          sync: 'Automatic synchronization',
          multiUser: 'Multi-employee management',
          notifications: 'Real-time notifications',
          history: 'Scan history'
        },
        cta: 'Download APK'
      },
      web: {
        badge: 'Web Dashboard',
        title: 'Centralized control',
        description: 'A complete dashboard for managers and administrators. View your stock, analyze trends, manage your teams and make informed decisions.',
        features: {
          dashboard: 'Interactive dashboards',
          reports: 'Detailed reports and exports',
          roles: 'Role and permission management',
          alerts: 'Configurable stock alerts',
          suppliers: 'Supplier and order management',
          billing: 'Billing and accounting'
        },
        cta: 'Request access'
      },
      client: {
        badge: 'Client Portal',
        badgeSoon: 'Soon',
        title: 'Your clients order online',
        description: 'Offer your clients a modern portal to place orders, track deliveries and view their history. Simplify your client relationship.',
        features: {
          orders: 'Online ordering',
          tracking: 'Real-time order tracking',
          history: 'History and invoices',
          delivery: 'Delivery tracking',
          payments: 'Secure payments',
          notifications: 'Status notifications'
        },
        cta: 'Get notified at launch'
      },
      integration: {
        title: 'Everything is connected',
        subtitle: 'Our three applications communicate in real-time for seamless management',
        mobile: 'Mobile',
        web: 'Web',
        sync: 'Real-time sync'
      },
      cta: {
        title: 'Ready to discover our solutions?',
        subtitle: 'Request a free personalized demonstration',
        button: 'Request a demo'
      }
    },
    // Vision Page
    vision: {
      badge: '🌍 Our Vision',
      title: 'Digitizing Africa,',
      titleLine2: 'one business at a time',
      subtitle: 'We believe every African business deserves modern tools to manage their operations efficiently.',
      mission: {
        title: 'Our Mission',
        lead: 'Provide African businesses with <strong>simple, reliable and affordable</strong> inventory management solutions, designed to work in real field conditions.',
        p1: 'We found that most existing solutions are either too complex, too expensive, or unsuitable for African realities (unstable internet, teams unfamiliar with digital tools, etc.).',
        p2: 'That\'s why we created <strong>Prise Inventaire</strong>: a solution designed by Africans, for Africans, with a deep understanding of local challenges.'
      },
      stats: {
        year: 'Year founded',
        countries: 'Target countries',
        madeIn: 'Made in Africa',
        ambition: 'Ambition'
      },
      values: {
        title: 'Our Values',
        subtitle: 'The principles that guide each of our decisions',
        innovation: {
          title: 'Innovation',
          description: 'We develop solutions adapted to African realities, not copies of Western products.'
        },
        proximity: {
          title: 'Proximity',
          description: 'Local support, on-site training, personalized guidance for each client.'
        },
        efficiency: {
          title: 'Efficiency',
          description: 'Simple and powerful tools that meet the real needs of businesses.'
        },
        accessibility: {
          title: 'Accessibility',
          description: 'Pricing adapted to African SMEs, without compromising on quality.'
        }
      },
      presence: {
        title: 'Our Presence in Africa',
        subtitle: 'We are building a solid network across the continent',
        active: 'Active',
        expansion: 'Expansion',
        soon: 'Coming soon',
        cameroon: {
          description: 'Our headquarters is based in Douala. We already support many Cameroonian businesses in their digital transformation.'
        },
        ivoryCoast: {
          description: 'We are expanding our services to Ivory Coast to support Ivorian businesses in their inventory management.'
        },
        senegal: {
          description: 'Senegal is part of our expansion plan to cover French-speaking West Africa.'
        },
        gabon: {
          description: 'We are preparing our entry into the Gabonese market to serve businesses in the sub-region.'
        }
      },
      whyAfrica: {
        title: 'Why Africa?',
        description: 'Africa is the continent of tomorrow. With a young population, sustained economic growth and rapid mobile adoption, the opportunities are immense.',
        stats: {
          population: 'inhabitants by 2030',
          youth: 'of the population is under 25',
          growth: 'average economic growth',
          mobile: 'mobile penetration'
        },
        conclusion: 'We want to be actors in this transformation by providing the tools that will enable African businesses to thrive.'
      },
      cta: {
        title: 'Join the adventure',
        subtitle: 'Be part of the businesses transforming Africa',
        contact: 'Contact us',
        offers: 'View our offers'
      }
    },
    // Pricing Page
    pricing: {
      title: 'Pricing adapted to your business',
      subtitle: 'Choose the plan that fits your needs. Scale at your own pace.',
      popular: 'Most popular',
      plans: {
        starter: {
          name: 'Starter',
          description: 'For small businesses getting started',
          cta: 'Get started'
        },
        business: {
          name: 'Business',
          description: 'For growing SMEs',
          cta: 'Choose Business'
        },
        enterprise: {
          name: 'Enterprise',
          description: 'For large companies',
          price: 'Custom',
          cta: 'Contact us'
        }
      },
      features: {
        mobileUsers: 'Mobile app ({count} users)',
        mobileUnlimited: 'Unlimited users',
        webAdmins: 'Web dashboard ({count} admins)',
        webUnlimited: 'Unlimited admins',
        products: 'Up to {count} products',
        productsUnlimited: 'Unlimited products',
        sectors: '{count} sector/warehouse',
        sectorsMultiple: 'Up to {count} sectors/warehouses',
        sectorsUnlimited: 'Unlimited sites',
        supportEmail: 'Email support',
        supportPriority: 'Priority support',
        supportDedicated: 'Dedicated 24/7 support',
        offline: 'Offline mode',
        reportsBasic: 'Basic reports',
        reportsAdvanced: 'Advanced reports',
        reportsCustom: 'Custom reports',
        multisite: 'Multi-site',
        api: 'Custom API',
        trainingNone: 'On-site training',
        trainingOne: 'On-site training (1 session)',
        trainingUnlimited: 'Unlimited training'
      },
      included: {
        title: 'Included in all plans',
        mobile: {
          title: 'Mobile App',
          description: 'Android with offline mode'
        },
        web: {
          title: 'Web Dashboard',
          description: 'Accessible from any browser'
        },
        updates: {
          title: 'Updates',
          description: 'Free new features'
        },
        support: {
          title: 'Support',
          description: 'Responsive French-speaking team'
        }
      },
      faq: {
        title: 'Frequently asked questions',
        q1: {
          question: 'Can I change plans at any time?',
          answer: 'Yes, you can upgrade or downgrade your plan at any time. Changes take effect at the next billing cycle.'
        },
        q2: {
          question: 'Is there a trial period?',
          answer: 'Yes, we offer a 14-day free trial on all plans, with no commitment and no credit card required.'
        },
        q3: {
          question: 'How does billing work?',
          answer: 'Billing is monthly. We accept Mobile Money (Orange Money, MTN MoMo), bank transfers and credit cards.'
        },
        q4: {
          question: 'What happens if I exceed my limits?',
          answer: 'We notify you in advance. You can either upgrade your plan or contact us for a custom solution.'
        },
        q5: {
          question: 'Is training included?',
          answer: 'Online training is included in all plans. On-site training is available in Business and Enterprise plans.'
        },
        q6: {
          question: 'Do you offer annual discounts?',
          answer: 'Yes! By paying annually, you get 2 months free (about 17% discount).'
        }
      },
      cta: {
        title: 'Need a custom plan?',
        subtitle: 'Contact us to discuss your specific needs',
        button: 'Talk to an advisor'
      }
    },
    // Training Page
    training: {
      title: 'Prise Inventaire Training',
      subtitle: 'Master our solution and maximize your productivity',
      formulas: {
        title: 'Our training options',
        subtitle: 'Choose the format that best suits your team',
        online: {
          title: 'Online Training',
          duration: '2 hours',
          price: 'Free',
          description: 'Complete video training to master the basics of Prise Inventaire.',
          features: ['Unlimited video access', 'Validation quiz', 'Completion certificate', 'Email support'],
          cta: 'Access',
          included: 'Included in all plans'
        },
        remote: {
          title: 'Remote Training',
          duration: '1 day',
          price: '50,000 FCFA',
          description: 'Video conference training session with a dedicated trainer.',
          features: ['Personalized training', 'Live Q&A', 'Practical exercises', '30-day support included'],
          cta: 'Book'
        },
        onsite: {
          title: 'On-site Training',
          duration: '1-2 days',
          price: '150,000 FCFA',
          description: 'A trainer comes to your premises to train your teams.',
          features: ['Training at your location', 'Adapted to your processes', 'Train the trainers', '60-day support included'],
          cta: 'Book'
        }
      },
      program: {
        title: 'Training Program',
        subtitle: 'A complete path to master all aspects of the solution',
        modules: {
          m1: { title: 'Getting Started', topics: ['App installation', 'Initial setup', 'User creation'] },
          m2: { title: 'Product Management', topics: ['Adding products', 'Categorization', 'Barcodes and QR codes'] },
          m3: { title: 'Field Inventory', topics: ['Using the scanner', 'Offline mode', 'Synchronization'] },
          m4: { title: 'Dashboard & Reports', topics: ['Reading statistics', 'Report generation', 'Stock alerts'] },
          m5: { title: 'Advanced Management', topics: ['Multi-site', 'Roles and permissions', 'Integrations'] }
        }
      },
      certification: {
        title: 'Prise Inventaire Certification',
        description: 'Upon completion of each training, receive a certificate attesting to your skills. This certificate is recognized by all companies using our solution.',
        features: ['Verifiable digital certificate', 'Permanent validity', 'LinkedIn badge available'],
        certTitle: 'Training Certificate',
        certSubtitle: 'Complete Training'
      },
      testimonials: {
        title: 'What our clients say',
        t1: {
          quote: 'The on-site training allowed our team of 15 people to be operational in 2 days. Excellent investment!',
          author: 'Marie K.',
          company: 'Le Bon Prix Supermarket, Douala'
        },
        t2: {
          quote: 'The online videos are very well made. I was able to train my employees myself thanks to these resources.',
          author: 'Jean-Paul M.',
          company: 'Modern Hardware Store, Yaoundé'
        }
      },
      sessions: {
        title: 'Upcoming sessions',
        description: 'We regularly organize group training sessions in Douala and Yaoundé. Limited spots!',
        register: 'Register',
        complete: 'Complete training',
        online: 'Online training',
        videoconference: 'Video conference'
      },
      cta: {
        title: 'Need custom training?',
        subtitle: 'Contact us to organize a session adapted to your needs',
        button: 'Request a quote'
      }
    },
    // Contact Page
    contact: {
      title: 'Contact Us',
      subtitle: 'Our team is here to answer all your questions',
      form: {
        title: 'Send us a message',
        name: 'Full name',
        namePlaceholder: 'Your name',
        email: 'Email',
        emailPlaceholder: 'your@email.com',
        phone: 'Phone',
        phonePlaceholder: '+237 6XX XXX XXX',
        company: 'Company',
        companyPlaceholder: 'Your company name',
        subject: 'Subject',
        subjects: {
          demo: 'Demo request',
          pricing: 'Pricing question',
          training: 'Training',
          support: 'Technical support',
          partnership: 'Partnership',
          other: 'Other'
        },
        message: 'Message',
        messagePlaceholder: 'Describe your needs...',
        submit: 'Send message',
        success: {
          title: 'Message sent!',
          description: 'Thank you for your message. Our team will respond as soon as possible (usually within 24 hours).',
          another: 'Send another message'
        }
      },
      info: {
        title: 'Our contact details',
        description: 'Feel free to contact us by the method that suits you best. We are available to answer your questions.',
        email: 'Email',
        phone: 'Phone',
        address: 'Address',
        hours: 'Hours',
        hoursValue: 'Mon-Fri: 8am-6pm'
      },
      social: {
        title: 'Follow us',
        description: 'Stay informed about our news and updates'
      }
    }
  }
};

export type Translations = typeof translations.fr;
