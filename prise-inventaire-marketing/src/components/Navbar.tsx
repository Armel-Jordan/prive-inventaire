import { useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import { Menu, X, Package, Globe } from 'lucide-react';
import { useLanguage } from '../i18n';
import './Navbar.css';

export default function Navbar() {
  const [isOpen, setIsOpen] = useState(false);
  const location = useLocation();
  const { language, setLanguage, t } = useLanguage();

  const navLinks = [
    { path: '/', label: t.nav.home },
    { path: '/solutions', label: t.nav.solutions },
    { path: '/vision', label: t.nav.vision },
    { path: '/tarifs', label: t.nav.pricing },
    { path: '/formations', label: t.nav.training },
    { path: '/contact', label: t.nav.contact },
  ];

  const toggleLanguage = () => {
    setLanguage(language === 'fr' ? 'en' : 'fr');
  };

  return (
    <nav className="navbar">
      <div className="container navbar-container">
        <Link to="/" className="navbar-logo">
          <Package size={32} />
          <span>Prise Inventaire</span>
        </Link>

        <ul className={`navbar-links ${isOpen ? 'active' : ''}`}>
          {navLinks.map((link) => (
            <li key={link.path}>
              <Link
                to={link.path}
                className={location.pathname === link.path ? 'active' : ''}
                onClick={() => setIsOpen(false)}
              >
                {link.label}
              </Link>
            </li>
          ))}
        </ul>

        <div className="navbar-actions">
          <button className="lang-toggle" onClick={toggleLanguage} title={language === 'fr' ? 'Switch to English' : 'Passer en français'}>
            <Globe size={18} />
            <span>{language.toUpperCase()}</span>
          </button>
          <Link to="/contact" className="btn btn-primary">
            {t.nav.demo}
          </Link>
        </div>

        <button className="navbar-toggle" onClick={() => setIsOpen(!isOpen)}>
          {isOpen ? <X size={24} /> : <Menu size={24} />}
        </button>
      </div>
    </nav>
  );
}
