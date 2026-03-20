import { Link } from 'react-router-dom';
import { 
  GraduationCap, 
  Users, 
  Monitor, 
  Clock,
  CheckCircle,
  ArrowRight,
  Calendar,
  MapPin,
  Video,
  Award
} from 'lucide-react';
import { useLanguage } from '../i18n';
import './FormationsPage.css';

export default function FormationsPage() {
  const { t } = useLanguage();

  const formations = [
    {
      icon: <Video size={32} />,
      title: t.training.formulas.online.title,
      duration: t.training.formulas.online.duration,
      price: t.training.formulas.online.price,
      description: t.training.formulas.online.description,
      features: t.training.formulas.online.features,
      included: true,
      cta: t.training.formulas.online.cta,
      badge: t.training.formulas.online.included
    },
    {
      icon: <Monitor size={32} />,
      title: t.training.formulas.remote.title,
      duration: t.training.formulas.remote.duration,
      price: t.training.formulas.remote.price,
      description: t.training.formulas.remote.description,
      features: t.training.formulas.remote.features,
      included: false,
      cta: t.training.formulas.remote.cta
    },
    {
      icon: <Users size={32} />,
      title: t.training.formulas.onsite.title,
      duration: t.training.formulas.onsite.duration,
      price: t.training.formulas.onsite.price,
      description: t.training.formulas.onsite.description,
      features: t.training.formulas.onsite.features,
      included: false,
      cta: t.training.formulas.onsite.cta
    }
  ];

  const modules = [
    { number: '01', title: t.training.program.modules.m1.title, topics: t.training.program.modules.m1.topics },
    { number: '02', title: t.training.program.modules.m2.title, topics: t.training.program.modules.m2.topics },
    { number: '03', title: t.training.program.modules.m3.title, topics: t.training.program.modules.m3.topics },
    { number: '04', title: t.training.program.modules.m4.title, topics: t.training.program.modules.m4.topics },
    { number: '05', title: t.training.program.modules.m5.title, topics: t.training.program.modules.m5.topics },
  ];

  const testimonials = [
    { quote: t.training.testimonials.t1.quote, author: t.training.testimonials.t1.author, company: t.training.testimonials.t1.company },
    { quote: t.training.testimonials.t2.quote, author: t.training.testimonials.t2.author, company: t.training.testimonials.t2.company },
  ];

  return (
    <div className="formations-page">
      {/* Hero */}
      <section className="formations-hero">
        <div className="container">
          <GraduationCap size={64} />
          <h1>{t.training.title}</h1>
          <p>{t.training.subtitle}</p>
        </div>
      </section>

      {/* Formations */}
      <section className="section formations-section">
        <div className="container">
          <h2 className="section-title">{t.training.formulas.title}</h2>
          <p className="section-subtitle">{t.training.formulas.subtitle}</p>
          <div className="formations-grid">
            {formations.map((formation, index) => (
              <div key={index} className={`formation-card ${formation.included ? 'included' : ''}`}>
                {formation.included && <span className="included-badge">{formation.badge}</span>}
                <div className="formation-icon">{formation.icon}</div>
                <h3>{formation.title}</h3>
                <div className="formation-meta">
                  <span><Clock size={16} /> {formation.duration}</span>
                  <span className="formation-price">{formation.price}</span>
                </div>
                <p>{formation.description}</p>
                <ul className="formation-features">
                  {formation.features.map((feature, i) => (
                    <li key={i}>
                      <CheckCircle size={16} />
                      {feature}
                    </li>
                  ))}
                </ul>
                <Link to="/contact" className="btn btn-primary">
                  {formation.cta} <ArrowRight size={18} />
                </Link>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Programme */}
      <section className="section programme-section">
        <div className="container">
          <h2 className="section-title">{t.training.program.title}</h2>
          <p className="section-subtitle">{t.training.program.subtitle}</p>
          <div className="modules-grid">
            {modules.map((module, index) => (
              <div key={index} className="module-card">
                <span className="module-number">{module.number}</span>
                <h3>{module.title}</h3>
                <ul>
                  {module.topics.map((topic, i) => (
                    <li key={i}>{topic}</li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Certification */}
      <section className="section certification-section">
        <div className="container">
          <div className="certification-content">
            <div className="certification-text">
              <Award size={48} />
              <h2>{t.training.certification.title}</h2>
              <p>{t.training.certification.description}</p>
              <ul>
                {t.training.certification.features.map((feature, i) => (
                  <li key={i}><CheckCircle size={20} /> {feature}</li>
                ))}
              </ul>
            </div>
            <div className="certification-visual">
              <div className="certificate-mockup">
                <div className="cert-header">
                  <GraduationCap size={32} />
                  <span>{t.training.certification.certTitle}</span>
                </div>
                <div className="cert-body">
                  <p>Prise Inventaire</p>
                  <h4>{t.training.certification.certSubtitle}</h4>
                  <div className="cert-line"></div>
                  <div className="cert-line short"></div>
                </div>
                <div className="cert-footer">
                  <Award size={24} />
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* Testimonials */}
      <section className="section testimonials-section">
        <div className="container">
          <h2 className="section-title">{t.training.testimonials.title}</h2>
          <div className="testimonials-grid">
            {testimonials.map((testimonial, index) => (
              <div key={index} className="testimonial-card">
                <p className="testimonial-quote">"{testimonial.quote}"</p>
                <div className="testimonial-author">
                  <strong>{testimonial.author}</strong>
                  <span>{testimonial.company}</span>
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* Calendar */}
      <section className="section calendar-section">
        <div className="container">
          <div className="calendar-content">
            <div className="calendar-info">
              <Calendar size={48} />
              <h2>{t.training.sessions.title}</h2>
              <p>{t.training.sessions.description}</p>
            </div>
            <div className="calendar-sessions">
              <div className="session-card">
                <div className="session-date">
                  <span className="day">15</span>
                  <span className="month">AVR</span>
                </div>
                <div className="session-info">
                  <h4>{t.training.sessions.complete}</h4>
                  <p><MapPin size={14} /> Douala, Cameroun</p>
                </div>
                <Link to="/contact" className="btn btn-secondary btn-sm">
                  {t.training.sessions.register}
                </Link>
              </div>
              <div className="session-card">
                <div className="session-date">
                  <span className="day">22</span>
                  <span className="month">AVR</span>
                </div>
                <div className="session-info">
                  <h4>{t.training.sessions.complete}</h4>
                  <p><MapPin size={14} /> Yaoundé, Cameroun</p>
                </div>
                <Link to="/contact" className="btn btn-secondary btn-sm">
                  {t.training.sessions.register}
                </Link>
              </div>
              <div className="session-card">
                <div className="session-date">
                  <span className="day">05</span>
                  <span className="month">MAI</span>
                </div>
                <div className="session-info">
                  <h4>{t.training.sessions.online}</h4>
                  <p><Video size={14} /> {t.training.sessions.videoconference}</p>
                </div>
                <Link to="/contact" className="btn btn-secondary btn-sm">
                  {t.training.sessions.register}
                </Link>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* CTA */}
      <section className="formations-cta">
        <div className="container">
          <h2>{t.training.cta.title}</h2>
          <p>{t.training.cta.subtitle}</p>
          <Link to="/contact" className="btn btn-accent">
            {t.training.cta.button} <ArrowRight size={18} />
          </Link>
        </div>
      </section>
    </div>
  );
}
