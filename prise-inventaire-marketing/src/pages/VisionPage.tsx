import { Link } from 'react-router-dom';
import { 
  Globe, 
  Target, 
  Heart, 
  Lightbulb,
  ArrowRight,
  CheckCircle,
  Users
} from 'lucide-react';
import { useLanguage } from '../i18n';
import './VisionPage.css';

export default function VisionPage() {
  const { t } = useLanguage();

  const values = [
    {
      icon: <Lightbulb size={32} />,
      title: t.vision.values.innovation.title,
      description: t.vision.values.innovation.description
    },
    {
      icon: <Heart size={32} />,
      title: t.vision.values.proximity.title,
      description: t.vision.values.proximity.description
    },
    {
      icon: <Target size={32} />,
      title: t.vision.values.efficiency.title,
      description: t.vision.values.efficiency.description
    },
    {
      icon: <Users size={32} />,
      title: t.vision.values.accessibility.title,
      description: t.vision.values.accessibility.description
    }
  ];

  const countries = [
    {
      flag: '🇨🇲',
      name: 'Cameroun',
      status: t.vision.presence.active,
      statusKey: 'actif',
      description: t.vision.presence.cameroon.description,
      cities: ['Douala', 'Yaoundé', 'Bafoussam']
    },
    {
      flag: '🇨🇮',
      name: 'Côte d\'Ivoire',
      status: t.vision.presence.expansion,
      statusKey: 'expansion',
      description: t.vision.presence.ivoryCoast.description,
      cities: ['Abidjan', 'Bouaké', 'San-Pédro']
    },
    {
      flag: '🇸🇳',
      name: 'Sénégal',
      status: t.vision.presence.soon,
      statusKey: 'prochainement',
      description: t.vision.presence.senegal.description,
      cities: ['Dakar', 'Thiès', 'Saint-Louis']
    },
    {
      flag: '🇬🇦',
      name: 'Gabon',
      status: t.vision.presence.soon,
      statusKey: 'prochainement',
      description: t.vision.presence.gabon.description,
      cities: ['Libreville', 'Port-Gentil']
    }
  ];

  const stats = [
    { value: '2024', label: t.vision.stats.year },
    { value: '5+', label: t.vision.stats.countries },
    { value: '100%', label: t.vision.stats.madeIn },
    { value: '∞', label: t.vision.stats.ambition }
  ];

  return (
    <div className="vision-page">
      {/* Hero */}
      <section className="vision-hero">
        <div className="container">
          <span className="vision-badge">{t.vision.badge}</span>
          <h1>{t.vision.title}<br />{t.vision.titleLine2}</h1>
          <p>{t.vision.subtitle}</p>
        </div>
      </section>

      {/* Mission */}
      <section className="section mission-section">
        <div className="container">
          <div className="mission-content">
            <div className="mission-text">
              <h2>{t.vision.mission.title}</h2>
              <p className="mission-lead" dangerouslySetInnerHTML={{ __html: t.vision.mission.lead }} />
              <p>{t.vision.mission.p1}</p>
              <p dangerouslySetInnerHTML={{ __html: t.vision.mission.p2 }} />
            </div>
            <div className="mission-stats">
              {stats.map((stat, index) => (
                <div key={index} className="mission-stat">
                  <span className="stat-value">{stat.value}</span>
                  <span className="stat-label">{stat.label}</span>
                </div>
              ))}
            </div>
          </div>
        </div>
      </section>

      {/* Values */}
      <section className="section values-section">
        <div className="container">
          <h2 className="section-title">{t.vision.values.title}</h2>
          <p className="section-subtitle">{t.vision.values.subtitle}</p>
          <div className="values-grid">
            {values.map((value, index) => (
              <div key={index} className="value-card">
                <div className="value-icon">{value.icon}</div>
                <h3>{value.title}</h3>
                <p>{value.description}</p>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Countries */}
      <section className="section countries-section">
        <div className="container">
          <h2 className="section-title">{t.vision.presence.title}</h2>
          <p className="section-subtitle">{t.vision.presence.subtitle}</p>
          <div className="countries-grid">
            {countries.map((country, index) => (
              <div key={index} className={`country-card ${country.statusKey}`}>
                <div className="country-header">
                  <span className="country-flag">{country.flag}</span>
                  <div>
                    <h3>{country.name}</h3>
                    <span className={`country-status ${country.statusKey}`}>
                      {country.status}
                    </span>
                  </div>
                </div>
                <p>{country.description}</p>
                <div className="country-cities">
                  {country.cities.map((city, i) => (
                    <span key={i} className="city-tag">{city}</span>
                  ))}
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Why Africa */}
      <section className="section why-africa">
        <div className="container">
          <div className="why-content">
            <div className="why-text">
              <h2>{t.vision.whyAfrica.title}</h2>
              <p>{t.vision.whyAfrica.description}</p>
              <ul className="why-list">
                <li>
                  <CheckCircle size={20} />
                  <span><strong>1,4 milliard</strong> {t.vision.whyAfrica.stats.population}</span>
                </li>
                <li>
                  <CheckCircle size={20} />
                  <span><strong>60%</strong> {t.vision.whyAfrica.stats.youth}</span>
                </li>
                <li>
                  <CheckCircle size={20} />
                  <span><strong>+5%</strong> {t.vision.whyAfrica.stats.growth}</span>
                </li>
                <li>
                  <CheckCircle size={20} />
                  <span><strong>80%</strong> {t.vision.whyAfrica.stats.mobile}</span>
                </li>
              </ul>
              <p>{t.vision.whyAfrica.conclusion}</p>
            </div>
            <div className="why-visual">
              <div className="africa-map">
                <Globe size={200} />
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* CTA */}
      <section className="vision-cta">
        <div className="container">
          <h2>{t.vision.cta.title}</h2>
          <p>{t.vision.cta.subtitle}</p>
          <div className="cta-buttons">
            <Link to="/contact" className="btn btn-accent">
              {t.vision.cta.contact} <ArrowRight size={18} />
            </Link>
            <Link to="/tarifs" className="btn btn-secondary">
              {t.vision.cta.offers}
            </Link>
          </div>
        </div>
      </section>
    </div>
  );
}
