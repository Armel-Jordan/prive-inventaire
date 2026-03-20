import { Link } from 'react-router-dom';
import { 
  Smartphone, 
  Monitor, 
  ShoppingCart, 
  CheckCircle, 
  ArrowRight,
  BarChart3,
  Shield,
  Zap,
  Globe
} from 'lucide-react';
import { useLanguage } from '../i18n';
import './HomePage.css';

export default function HomePage() {
  const { t } = useLanguage();

  const features = [
    {
      icon: <BarChart3 size={32} />,
      title: t.home.features.realtime.title,
      description: t.home.features.realtime.description
    },
    {
      icon: <Shield size={32} />,
      title: t.home.features.security.title,
      description: t.home.features.security.description
    },
    {
      icon: <Zap size={32} />,
      title: t.home.features.offline.title,
      description: t.home.features.offline.description
    },
    {
      icon: <Globe size={32} />,
      title: t.home.features.multisite.title,
      description: t.home.features.multisite.description
    }
  ];

  const solutions = [
    {
      icon: <Smartphone size={48} />,
      title: t.home.solutions.mobile.title,
      description: t.home.solutions.mobile.description,
      color: '#10b981'
    },
    {
      icon: <Monitor size={48} />,
      title: t.home.solutions.web.title,
      description: t.home.solutions.web.description,
      color: '#2563eb'
    },
    {
      icon: <ShoppingCart size={48} />,
      title: t.home.solutions.client.title,
      description: t.home.solutions.client.description,
      color: '#f59e0b',
      badge: t.home.solutions.client.badge
    }
  ];

  const stats = [
    { value: '500+', label: t.home.stats.companies },
    { value: '50K+', label: t.home.stats.products },
    { value: '99.9%', label: t.home.stats.uptime },
    { value: '24/7', label: t.home.stats.support }
  ];

  return (
    <div className="home-page">
      {/* Hero Section */}
      <section className="hero">
        <div className="container">
          <div className="hero-content">
            <span className="hero-badge">{t.home.badge}</span>
            <h1>{t.home.title} <span className="gradient-text">{t.home.titleHighlight}</span></h1>
            <p>{t.home.subtitle}</p>
            <div className="hero-buttons">
              <Link to="/contact" className="btn btn-primary">
                {t.home.ctaDemo} <ArrowRight size={18} />
              </Link>
              <Link to="/solutions" className="btn btn-secondary">
                {t.home.ctaDiscover}
              </Link>
            </div>
          </div>
          <div className="hero-visual">
            <div className="hero-mockup">
              <div className="mockup-phone">
                <div className="mockup-screen">
                  <div className="mockup-header"></div>
                  <div className="mockup-content">
                    <div className="mockup-card"></div>
                    <div className="mockup-card"></div>
                    <div className="mockup-card small"></div>
                  </div>
                </div>
              </div>
              <div className="mockup-dashboard">
                <div className="mockup-nav"></div>
                <div className="mockup-main">
                  <div className="mockup-chart"></div>
                  <div className="mockup-stats">
                    <div className="mockup-stat"></div>
                    <div className="mockup-stat"></div>
                    <div className="mockup-stat"></div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* Stats Section */}
      <section className="stats-section">
        <div className="container">
          <div className="stats-grid">
            {stats.map((stat, index) => (
              <div key={index} className="stat-item">
                <span className="stat-value">{stat.value}</span>
                <span className="stat-label">{stat.label}</span>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Solutions Section */}
      <section className="section solutions-section">
        <div className="container">
          <h2 className="section-title">{t.home.ecosystem}</h2>
          <p className="section-subtitle">{t.home.ecosystemSubtitle}</p>
          <div className="solutions-grid">
            {solutions.map((solution, index) => (
              <div key={index} className="solution-card">
                {solution.badge && <span className="solution-badge">{solution.badge}</span>}
                <div className="solution-icon" style={{ backgroundColor: `${solution.color}15`, color: solution.color }}>
                  {solution.icon}
                </div>
                <h3>{solution.title}</h3>
                <p>{solution.description}</p>
                <Link to="/solutions" className="solution-link">
                  {t.home.learnMore} <ArrowRight size={16} />
                </Link>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Features Section */}
      <section className="section features-section">
        <div className="container">
          <h2 className="section-title">{t.home.whyChoose}</h2>
          <p className="section-subtitle">{t.home.whyChooseSubtitle}</p>
          <div className="features-grid">
            {features.map((feature, index) => (
              <div key={index} className="feature-card">
                <div className="feature-icon">{feature.icon}</div>
                <h3>{feature.title}</h3>
                <p>{feature.description}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Africa Section */}
      <section className="section africa-section">
        <div className="container">
          <div className="africa-content">
            <div className="africa-text">
              <span className="africa-badge">{t.home.africaMission}</span>
              <h2>{t.home.africaTitle}</h2>
              <p>{t.home.africaDescription}</p>
              <ul className="africa-list">
                <li><CheckCircle size={20} /> {t.home.africaFeatures.offline}</li>
                <li><CheckCircle size={20} /> {t.home.africaFeatures.simple}</li>
                <li><CheckCircle size={20} /> {t.home.africaFeatures.french}</li>
                <li><CheckCircle size={20} /> {t.home.africaFeatures.training}</li>
              </ul>
              <Link to="/vision" className="btn btn-primary">
                {t.home.discoverVision} <ArrowRight size={18} />
              </Link>
            </div>
            <div className="africa-countries">
              <div className="country-card">
                <span className="country-flag">🇨🇲</span>
                <h4>{t.home.countries.cameroon.name}</h4>
                <p>{t.home.countries.cameroon.description}</p>
              </div>
              <div className="country-card">
                <span className="country-flag">🇨🇮</span>
                <h4>{t.home.countries.ivoryCoast.name}</h4>
                <p>{t.home.countries.ivoryCoast.description}</p>
              </div>
              <div className="country-card coming">
                <span className="country-flag">🌍</span>
                <h4>{t.home.countries.westAfrica.name}</h4>
                <p>{t.home.countries.westAfrica.description}</p>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* CTA Section */}
      <section className="cta-section">
        <div className="container">
          <div className="cta-content">
            <h2>{t.home.ctaTitle}</h2>
            <p>{t.home.ctaSubtitle}</p>
            <div className="cta-buttons">
              <Link to="/contact" className="btn btn-accent">
                {t.home.ctaDemoFree}
              </Link>
              <Link to="/tarifs" className="btn btn-secondary">
                {t.home.ctaPricing}
              </Link>
            </div>
          </div>
        </div>
      </section>
    </div>
  );
}
