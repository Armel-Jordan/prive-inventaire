import './Loader.css';

export default function Loader() {
  return (
    <div className="loader-container">
      <div className="loader">
        <div className="loader-spinner"></div>
        <p>Chargement...</p>
      </div>
    </div>
  );
}
