import { Link } from 'react-router-dom';
import { 
  Smartphone, 
  Monitor, 
  ShoppingCart, 
  ArrowRight,
  Scan,
  Wifi,
  WifiOff,
  BarChart3,
  Users,
  Bell,
  FileText,
  Truck,
  CreditCard,
  Clock
} from 'lucide-react';
import { useLanguage } from '../i18n';
import './SolutionsPage.css';

export default function SolutionsPage() {
  const { t } = useLanguage();

  const mobileFeatures = [
    { icon: <Scan size={20} />, text: t.solutions.mobile.features.scan },
    { icon: <WifiOff size={20} />, text: t.solutions.mobile.features.offline },
    { icon: <Clock size={20} />, text: t.solutions.mobile.features.sync },
    { icon: <Users size={20} />, text: t.solutions.mobile.features.multiUser },
    { icon: <Bell size={20} />, text: t.solutions.mobile.features.notifications },
    { icon: <FileText size={20} />, text: t.solutions.mobile.features.history },
  ];

  const webFeatures = [
    { icon: <BarChart3 size={20} />, text: t.solutions.web.features.dashboard },
    { icon: <FileText size={20} />, text: t.solutions.web.features.reports },
    { icon: <Users size={20} />, text: t.solutions.web.features.roles },
    { icon: <Bell size={20} />, text: t.solutions.web.features.alerts },
    { icon: <Truck size={20} />, text: t.solutions.web.features.suppliers },
    { icon: <CreditCard size={20} />, text: t.solutions.web.features.billing },
  ];

  const clientFeatures = [
    { icon: <ShoppingCart size={20} />, text: t.solutions.client.features.orders },
    { icon: <Clock size={20} />, text: t.solutions.client.features.tracking },
    { icon: <FileText size={20} />, text: t.solutions.client.features.history },
    { icon: <Truck size={20} />, text: t.solutions.client.features.delivery },
    { icon: <CreditCard size={20} />, text: t.solutions.client.features.payments },
    { icon: <Bell size={20} />, text: t.solutions.client.features.notifications },
  ];

  return (
    <div className="solutions-page">
      {/* Hero */}
      <section className="solutions-hero">
        <div className="container">
          <h1>{t.solutions.title}</h1>
          <p>{t.solutions.subtitle}</p>
        </div>
      </section>

      {/* Mobile App */}
      <section className="solution-detail section" id="mobile">
        <div className="container">
          <div className="solution-content">
            <div className="solution-info">
              <div className="solution-badge mobile">
                <Smartphone size={24} />
                {t.solutions.mobile.badge}
              </div>
              <h2>{t.solutions.mobile.title}</h2>
              <p>{t.solutions.mobile.description}</p>
              <ul className="solution-features">
                {mobileFeatures.map((feature, index) => (
                  <li key={index}>
                    <span className="feature-icon">{feature.icon}</span>
                    {feature.text}
                  </li>
                ))}
              </ul>
              <div className="solution-actions">
                <Link to="/contact" className="btn btn-primary">
                  {t.solutions.mobile.cta} <ArrowRight size={18} />
                </Link>
              </div>
            </div>
            <div className="solution-visual">
              <div className="phone-mockup">
                <div className="phone-screen">
                  <div className="app-header">
                    <span>Prise Inventaire</span>
                  </div>
                  <div className="app-content">
                    <div className="scan-area">
                      <Scan size={48} />
                      <span>Scanner un produit</span>
                    </div>
                    <div className="app-stats">
                      <div className="app-stat">
                        <strong>127</strong>
                        <span>Scans aujourd'hui</span>
                      </div>
                      <div className="app-stat">
                        <strong>3</strong>
                        <span>Secteurs</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* Web Dashboard */}
      <section className="solution-detail section alt" id="web">
        <div className="container">
          <div className="solution-content reverse">
            <div className="solution-info">
              <div className="solution-badge web">
                <Monitor size={24} />
                {t.solutions.web.badge}
              </div>
              <h2>{t.solutions.web.title}</h2>
              <p>{t.solutions.web.description}</p>
              <ul className="solution-features">
                {webFeatures.map((feature, index) => (
                  <li key={index}>
                    <span className="feature-icon">{feature.icon}</span>
                    {feature.text}
                  </li>
                ))}
              </ul>
              <div className="solution-actions">
                <Link to="/contact" className="btn btn-primary">
                  {t.solutions.web.cta} <ArrowRight size={18} />
                </Link>
              </div>
            </div>
            <div className="solution-visual">
              <div className="dashboard-mockup">
                <div className="dashboard-nav">
                  <div className="nav-logo"></div>
                  <div className="nav-items">
                    <div className="nav-item active"></div>
                    <div className="nav-item"></div>
                    <div className="nav-item"></div>
                    <div className="nav-item"></div>
                  </div>
                </div>
                <div className="dashboard-content">
                  <div className="dash-header">
                    <div className="dash-title"></div>
                    <div className="dash-actions"></div>
                  </div>
                  <div className="dash-stats">
                    <div className="dash-stat-card"></div>
                    <div className="dash-stat-card"></div>
                    <div className="dash-stat-card"></div>
                    <div className="dash-stat-card"></div>
                  </div>
                  <div className="dash-chart"></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* Client Portal */}
      <section className="solution-detail section" id="client">
        <div className="container">
          <div className="solution-content">
            <div className="solution-info">
              <div className="solution-badge client">
                <ShoppingCart size={24} />
                {t.solutions.client.badge}
                <span className="coming-badge">{t.solutions.client.badgeSoon}</span>
              </div>
              <h2>{t.solutions.client.title}</h2>
              <p>{t.solutions.client.description}</p>
              <ul className="solution-features">
                {clientFeatures.map((feature, index) => (
                  <li key={index}>
                    <span className="feature-icon">{feature.icon}</span>
                    {feature.text}
                  </li>
                ))}
              </ul>
              <div className="solution-actions">
                <Link to="/contact" className="btn btn-secondary">
                  {t.solutions.client.cta} <Bell size={18} />
                </Link>
              </div>
            </div>
            <div className="solution-visual">
              <div className="client-mockup">
                <div className="client-header">
                  <div className="client-logo"></div>
                  <div className="client-nav"></div>
                </div>
                <div className="client-content">
                  <div className="product-grid">
                    <div className="product-card">
                      <div className="product-img"></div>
                      <div className="product-info"></div>
                    </div>
                    <div className="product-card">
                      <div className="product-img"></div>
                      <div className="product-info"></div>
                    </div>
                    <div className="product-card">
                      <div className="product-img"></div>
                      <div className="product-info"></div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* Integration */}
      <section className="integration-section section">
        <div className="container">
          <h2 className="section-title">{t.solutions.integration.title}</h2>
          <p className="section-subtitle">{t.solutions.integration.subtitle}</p>
          <div className="integration-diagram">
            <div className="integration-app">
              <Smartphone size={32} />
              <span>{t.solutions.integration.mobile}</span>
            </div>
            <div className="integration-line"></div>
            <div className="integration-center">
              <Wifi size={40} />
              <span>{t.solutions.integration.sync}</span>
            </div>
            <div className="integration-line"></div>
            <div className="integration-app">
              <Monitor size={32} />
              <span>{t.solutions.integration.web}</span>
            </div>
          </div>
        </div>
      </section>

      {/* CTA */}
      <section className="solutions-cta">
        <div className="container">
          <h2>{t.solutions.cta.title}</h2>
          <p>{t.solutions.cta.subtitle}</p>
          <Link to="/contact" className="btn btn-accent">
            {t.solutions.cta.button} <ArrowRight size={18} />
          </Link>
        </div>
      </section>
    </div>
  );
}
