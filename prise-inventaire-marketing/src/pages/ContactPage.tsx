import { useState } from 'react';
import { 
  Mail, 
  Phone, 
  MapPin, 
  Send,
  MessageSquare,
  Clock,
  CheckCircle
} from 'lucide-react';
import { useLanguage } from '../i18n';
import './ContactPage.css';

export default function ContactPage() {
  const { t } = useLanguage();
  const [formData, setFormData] = useState({
    name: '',
    email: '',
    phone: '',
    company: '',
    subject: 'demo',
    message: ''
  });
  const [submitted, setSubmitted] = useState(false);

  const handleChange = (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>) => {
    setFormData({
      ...formData,
      [e.target.name]: e.target.value
    });
  };

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    setSubmitted(true);
  };

  const contactInfo = [
    {
      icon: <Mail size={24} />,
      title: t.contact.info.email,
      value: 'contact@prise-inventaire.com',
      link: 'mailto:contact@prise-inventaire.com'
    },
    {
      icon: <Phone size={24} />,
      title: t.contact.info.phone,
      value: '+237 6XX XXX XXX',
      link: 'tel:+237600000000'
    },
    {
      icon: <MapPin size={24} />,
      title: t.contact.info.address,
      value: 'Douala, Cameroun',
      link: null
    },
    {
      icon: <Clock size={24} />,
      title: t.contact.info.hours,
      value: t.contact.info.hoursValue,
      link: null
    }
  ];

  return (
    <div className="contact-page">
      {/* Hero */}
      <section className="contact-hero">
        <div className="container">
          <MessageSquare size={64} />
          <h1>{t.contact.title}</h1>
          <p>{t.contact.subtitle}</p>
        </div>
      </section>

      {/* Contact Section */}
      <section className="section contact-section">
        <div className="container">
          <div className="contact-grid">
            {/* Form */}
            <div className="contact-form-wrapper">
              {submitted ? (
                <div className="success-message">
                  <CheckCircle size={64} />
                  <h3>{t.contact.form.success.title}</h3>
                  <p>{t.contact.form.success.description}</p>
                  <button 
                    className="btn btn-primary"
                    onClick={() => setSubmitted(false)}
                  >
                    {t.contact.form.success.another}
                  </button>
                </div>
              ) : (
                <>
                  <h2>{t.contact.form.title}</h2>
                  <form onSubmit={handleSubmit} className="contact-form">
                    <div className="form-row">
                      <div className="form-group">
                        <label htmlFor="name">{t.contact.form.name} *</label>
                        <input
                          type="text"
                          id="name"
                          name="name"
                          value={formData.name}
                          onChange={handleChange}
                          required
                          placeholder={t.contact.form.namePlaceholder}
                        />
                      </div>
                      <div className="form-group">
                        <label htmlFor="email">{t.contact.form.email} *</label>
                        <input
                          type="email"
                          id="email"
                          name="email"
                          value={formData.email}
                          onChange={handleChange}
                          required
                          placeholder={t.contact.form.emailPlaceholder}
                        />
                      </div>
                    </div>

                    <div className="form-row">
                      <div className="form-group">
                        <label htmlFor="phone">{t.contact.form.phone}</label>
                        <input
                          type="tel"
                          id="phone"
                          name="phone"
                          value={formData.phone}
                          onChange={handleChange}
                          placeholder={t.contact.form.phonePlaceholder}
                        />
                      </div>
                      <div className="form-group">
                        <label htmlFor="company">{t.contact.form.company}</label>
                        <input
                          type="text"
                          id="company"
                          name="company"
                          value={formData.company}
                          onChange={handleChange}
                          placeholder={t.contact.form.companyPlaceholder}
                        />
                      </div>
                    </div>

                    <div className="form-group">
                      <label htmlFor="subject">{t.contact.form.subject} *</label>
                      <select
                        id="subject"
                        name="subject"
                        value={formData.subject}
                        onChange={handleChange}
                        required
                      >
                        <option value="demo">{t.contact.form.subjects.demo}</option>
                        <option value="pricing">{t.contact.form.subjects.pricing}</option>
                        <option value="training">{t.contact.form.subjects.training}</option>
                        <option value="support">{t.contact.form.subjects.support}</option>
                        <option value="partnership">{t.contact.form.subjects.partnership}</option>
                        <option value="other">{t.contact.form.subjects.other}</option>
                      </select>
                    </div>

                    <div className="form-group">
                      <label htmlFor="message">{t.contact.form.message} *</label>
                      <textarea
                        id="message"
                        name="message"
                        value={formData.message}
                        onChange={handleChange}
                        required
                        rows={5}
                        placeholder={t.contact.form.messagePlaceholder}
                      />
                    </div>

                    <button type="submit" className="btn btn-primary btn-submit">
                      {t.contact.form.submit} <Send size={18} />
                    </button>
                  </form>
                </>
              )}
            </div>

            {/* Info */}
            <div className="contact-info">
              <h2>{t.contact.info.title}</h2>
              <p>{t.contact.info.description}</p>

              <div className="info-cards">
                {contactInfo.map((info, index) => (
                  <div key={index} className="info-card">
                    <div className="info-icon">{info.icon}</div>
                    <div className="info-content">
                      <h4>{info.title}</h4>
                      {info.link ? (
                        <a href={info.link}>{info.value}</a>
                      ) : (
                        <span>{info.value}</span>
                      )}
                    </div>
                  </div>
                ))}
              </div>

              <div className="social-cta">
                <h4>{t.contact.social.title}</h4>
                <p>{t.contact.social.description}</p>
                <div className="social-links">
                  <a href="#" className="social-link">Facebook</a>
                  <a href="#" className="social-link">LinkedIn</a>
                  <a href="#" className="social-link">Twitter</a>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      {/* Map placeholder */}
      <section className="map-section">
        <div className="map-placeholder">
          <MapPin size={48} />
          <p>Douala, Cameroun</p>
        </div>
      </section>
    </div>
  );
}
