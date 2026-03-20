import { Link } from 'react-router-dom';
import { Package, Mail, Phone, MapPin, Facebook, Linkedin, Twitter } from 'lucide-react';
import { useLanguage } from '../i18n';
import './Footer.css';

export default function Footer() {
  const { t } = useLanguage();

  return (
    <footer className="footer">
      <div className="container">
        <div className="footer-grid">
          <div className="footer-brand">
            <Link to="/" className="footer-logo">
              <Package size={28} />
              <span>Prise Inventaire</span>
            </Link>
            <p>{t.footer.description}</p>
            <div className="footer-social">
              <a href="#" aria-label="Facebook"><Facebook size={20} /></a>
              <a href="#" aria-label="LinkedIn"><Linkedin size={20} /></a>
              <a href="#" aria-label="Twitter"><Twitter size={20} /></a>
            </div>
          </div>

          <div className="footer-links">
            <h4>{t.footer.solutions}</h4>
            <ul>
              <li><Link to="/solutions">{t.footer.mobileApp}</Link></li>
              <li><Link to="/solutions">{t.footer.webDashboard}</Link></li>
              <li><Link to="/solutions">{t.footer.clientPortal}</Link></li>
              <li><Link to="/tarifs">{t.footer.ourPacks}</Link></li>
            </ul>
          </div>

          <div className="footer-links">
            <h4>{t.footer.company}</h4>
            <ul>
              <li><Link to="/vision">{t.footer.ourVision}</Link></li>
              <li><Link to="/formations">{t.footer.training}</Link></li>
              <li><Link to="/contact">{t.footer.contact}</Link></li>
              <li><a href="#">{t.footer.careers}</a></li>
            </ul>
          </div>

          <div className="footer-contact">
            <h4>{t.footer.contactTitle}</h4>
            <ul>
              <li>
                <Mail size={18} />
                <span>contact@prise-inventaire.com</span>
              </li>
              <li>
                <Phone size={18} />
                <span>+237 6XX XXX XXX</span>
              </li>
              <li>
                <MapPin size={18} />
                <span>Douala, Cameroun</span>
              </li>
            </ul>
          </div>
        </div>

        <div className="footer-bottom">
          <p>{t.footer.copyright.replace('{year}', new Date().getFullYear().toString())}</p>
          <div className="footer-bottom-links">
            <Link to="/mentions-legales">{t.footer.legal}</Link>
            <Link to="/confidentialite">{t.footer.privacy}</Link>
            <Link to="/cgv">{t.footer.terms}</Link>
          </div>
        </div>
      </div>
    </footer>
  );
}
