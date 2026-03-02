import { useEffect, useState } from 'react';
import { User, Camera, Save, MapPin, Phone, Calendar, Briefcase } from 'lucide-react';
import { useAuth } from '@/contexts/AuthContext';

const API_BASE_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api';
const STORAGE_KEY = 'prise_auth';

function getAuthHeaders(): Record<string, string> {
  const headers: Record<string, string> = { 'Accept': 'application/json', 'Content-Type': 'application/json' };
  const stored = localStorage.getItem(STORAGE_KEY);
  if (stored) {
    try {
      const data = JSON.parse(stored);
      if (data.token) headers['Authorization'] = `Bearer ${data.token}`;
      if (data.tenant?.slug) headers['X-Tenant-Slug'] = data.tenant.slug;
    } catch { /* ignore */ }
  }
  return headers;
}

interface ProfileData {
  numero: string;
  nom: string;
  prenom: string;
  email: string;
  photo: string | null;
  sexe: 'M' | 'F' | 'autre' | null;
  date_naissance: string | null;
  telephone: string | null;
  adresse: string | null;
  ville: string | null;
  code_postal: string | null;
  pays: string | null;
  poste: string | null;
  departement: string | null;
  date_embauche: string | null;
}

export default function ProfilPage() {
  const { user } = useAuth();
  const [profile, setProfile] = useState<ProfileData | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
  const [photoFile, setPhotoFile] = useState<File | null>(null);
  const [photoPreview, setPhotoPreview] = useState<string | null>(null);

  const [formData, setFormData] = useState({
    telephone: '',
    adresse: '',
    ville: '',
    code_postal: '',
    pays: '',
    sexe: '' as '' | 'M' | 'F' | 'autre',
    date_naissance: '',
    poste: '',
    departement: '',
  });

  useEffect(() => {
    loadProfile();
  }, []);

  async function loadProfile() {
    setLoading(true);
    try {
      const res = await fetch(`${API_BASE_URL}/profile`, { headers: getAuthHeaders() });
      if (res.ok) {
        const data = await res.json();
        setProfile(data.employe);
        setFormData({
          telephone: data.employe.telephone || '',
          adresse: data.employe.adresse || '',
          ville: data.employe.ville || '',
          code_postal: data.employe.code_postal || '',
          pays: data.employe.pays || '',
          sexe: data.employe.sexe || '',
          date_naissance: data.employe.date_naissance || '',
          poste: data.employe.poste || '',
          departement: data.employe.departement || '',
        });
        if (data.employe.photo) {
          setPhotoPreview(`${API_BASE_URL.replace('/api', '')}/storage/${data.employe.photo}`);
        }
      }
    } catch (error) {
      console.error('Erreur chargement profil:', error);
    } finally {
      setLoading(false);
    }
  }

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setSaving(true);
    setMessage(null);

    try {
      const res = await fetch(`${API_BASE_URL}/profile`, {
        method: 'PUT',
        headers: getAuthHeaders(),
        body: JSON.stringify(formData),
      });

      if (res.ok) {
        setMessage({ type: 'success', text: 'Profil mis à jour avec succès' });
        loadProfile();
      } else {
        const data = await res.json();
        setMessage({ type: 'error', text: data.message || 'Erreur lors de la mise à jour' });
      }
    } catch {
      setMessage({ type: 'error', text: 'Erreur de connexion au serveur' });
    } finally {
      setSaving(false);
    }
  }

  async function handlePhotoUpload() {
    if (!photoFile) return;

    const formDataPhoto = new FormData();
    formDataPhoto.append('photo', photoFile);

    setSaving(true);
    try {
      const stored = localStorage.getItem(STORAGE_KEY);
      const headers: Record<string, string> = { 'Accept': 'application/json' };
      if (stored) {
        const data = JSON.parse(stored);
        if (data.token) headers['Authorization'] = `Bearer ${data.token}`;
        if (data.tenant?.slug) headers['X-Tenant-Slug'] = data.tenant.slug;
      }

      const res = await fetch(`${API_BASE_URL}/profile/photo`, {
        method: 'POST',
        headers,
        body: formDataPhoto,
      });

      if (res.ok) {
        setMessage({ type: 'success', text: 'Photo mise à jour avec succès' });
        setPhotoFile(null);
        loadProfile();
      } else {
        setMessage({ type: 'error', text: 'Erreur lors du téléchargement de la photo' });
      }
    } catch {
      setMessage({ type: 'error', text: 'Erreur de connexion au serveur' });
    } finally {
      setSaving(false);
    }
  }

  function handlePhotoChange(e: React.ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0];
    if (file) {
      setPhotoFile(file);
      const reader = new FileReader();
      reader.onloadend = () => {
        setPhotoPreview(reader.result as string);
      };
      reader.readAsDataURL(file);
    }
  }

  if (loading) {
    return <div className="text-gray-500">Chargement du profil...</div>;
  }

  if (!profile) {
    return (
      <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-6 text-center">
        <User className="mx-auto text-yellow-500 mb-4" size={48} />
        <h2 className="text-lg font-semibold text-yellow-800 mb-2">Profil non trouvé</h2>
        <p className="text-yellow-600">
          Votre compte utilisateur n'est pas associé à un employé. 
          Contactez votre administrateur pour créer votre profil employé.
        </p>
      </div>
    );
  }

  return (
    <div className="max-w-4xl mx-auto space-y-6">
      <div>
        <h1 className="text-2xl font-bold text-gray-800 dark:text-white">Mon Profil</h1>
        <p className="text-gray-500 dark:text-gray-400">Complétez vos informations personnelles</p>
      </div>

      {message && (
        <div className={`p-4 rounded-lg ${message.type === 'success' ? 'bg-green-50 text-green-700 border border-green-200' : 'bg-red-50 text-red-700 border border-red-200'}`}>
          {message.text}
        </div>
      )}

      {/* Photo de profil */}
      <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-6">
        <h2 className="text-lg font-semibold text-gray-800 dark:text-white mb-4">Photo de profil</h2>
        <div className="flex items-center gap-6">
          <div className="relative">
            <div className="w-24 h-24 rounded-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center overflow-hidden">
              {photoPreview ? (
                <img src={photoPreview} alt="Photo de profil" className="w-full h-full object-cover" />
              ) : (
                <User size={40} className="text-gray-400" />
              )}
            </div>
            <label className="absolute bottom-0 right-0 p-2 bg-blue-600 rounded-full cursor-pointer hover:bg-blue-700 transition-colors">
              <Camera size={16} className="text-white" />
              <input type="file" accept="image/*" className="hidden" onChange={handlePhotoChange} />
            </label>
          </div>
          <div>
            <p className="text-sm text-gray-600 dark:text-gray-400 mb-2">
              Formats acceptés: JPG, PNG, GIF (max 2MB)
            </p>
            {photoFile && (
              <button
                onClick={handlePhotoUpload}
                disabled={saving}
                className="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50"
              >
                Enregistrer la photo
              </button>
            )}
          </div>
        </div>
      </div>

      {/* Informations de base (lecture seule) */}
      <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-6">
        <h2 className="text-lg font-semibold text-gray-800 dark:text-white mb-4">Informations de base</h2>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Numéro employé</label>
            <p className="px-3 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg text-gray-800 dark:text-white font-mono">
              {profile.numero}
            </p>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Email</label>
            <p className="px-3 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg text-gray-800 dark:text-white">
              {profile.email || user?.email || '-'}
            </p>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Nom</label>
            <p className="px-3 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg text-gray-800 dark:text-white">
              {profile.nom}
            </p>
          </div>
          <div>
            <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Prénom</label>
            <p className="px-3 py-2 bg-gray-100 dark:bg-gray-700 rounded-lg text-gray-800 dark:text-white">
              {profile.prenom || '-'}
            </p>
          </div>
        </div>
      </div>

      {/* Formulaire de profil */}
      <form onSubmit={handleSubmit} className="space-y-6">
        {/* Informations personnelles */}
        <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-6">
          <h2 className="text-lg font-semibold text-gray-800 dark:text-white mb-4 flex items-center gap-2">
            <User size={20} />
            Informations personnelles
          </h2>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Sexe</label>
              <select
                value={formData.sexe}
                onChange={(e) => setFormData({ ...formData, sexe: e.target.value as '' | 'M' | 'F' | 'autre' })}
                className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-white"
              >
                <option value="">Non spécifié</option>
                <option value="M">Homme</option>
                <option value="F">Femme</option>
                <option value="autre">Autre</option>
              </select>
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">
                <Calendar size={14} className="inline mr-1" />
                Date de naissance
              </label>
              <input
                type="date"
                value={formData.date_naissance}
                onChange={(e) => setFormData({ ...formData, date_naissance: e.target.value })}
                className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-white"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">
                <Phone size={14} className="inline mr-1" />
                Téléphone
              </label>
              <input
                type="tel"
                value={formData.telephone}
                onChange={(e) => setFormData({ ...formData, telephone: e.target.value })}
                placeholder="+1 234 567 8900"
                className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-white"
              />
            </div>
          </div>
        </div>

        {/* Adresse */}
        <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-6">
          <h2 className="text-lg font-semibold text-gray-800 dark:text-white mb-4 flex items-center gap-2">
            <MapPin size={20} />
            Adresse
          </h2>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div className="md:col-span-2">
              <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Adresse</label>
              <input
                type="text"
                value={formData.adresse}
                onChange={(e) => setFormData({ ...formData, adresse: e.target.value })}
                placeholder="123 rue Exemple"
                className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-white"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Ville</label>
              <input
                type="text"
                value={formData.ville}
                onChange={(e) => setFormData({ ...formData, ville: e.target.value })}
                placeholder="Montréal"
                className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-white"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Code postal</label>
              <input
                type="text"
                value={formData.code_postal}
                onChange={(e) => setFormData({ ...formData, code_postal: e.target.value })}
                placeholder="H1A 1A1"
                className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-white"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Pays</label>
              <input
                type="text"
                value={formData.pays}
                onChange={(e) => setFormData({ ...formData, pays: e.target.value })}
                placeholder="Canada"
                className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-white"
              />
            </div>
          </div>
        </div>

        {/* Informations professionnelles */}
        <div className="bg-white dark:bg-gray-800 rounded-xl shadow-sm p-6">
          <h2 className="text-lg font-semibold text-gray-800 dark:text-white mb-4 flex items-center gap-2">
            <Briefcase size={20} />
            Informations professionnelles
          </h2>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Poste</label>
              <input
                type="text"
                value={formData.poste}
                onChange={(e) => setFormData({ ...formData, poste: e.target.value })}
                placeholder="Magasinier"
                className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-white"
              />
            </div>
            <div>
              <label className="block text-sm font-medium text-gray-600 dark:text-gray-400 mb-1">Département</label>
              <input
                type="text"
                value={formData.departement}
                onChange={(e) => setFormData({ ...formData, departement: e.target.value })}
                placeholder="Logistique"
                className="w-full px-3 py-2 border dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-800 dark:text-white"
              />
            </div>
          </div>
        </div>

        {/* Bouton de sauvegarde */}
        <div className="flex justify-end">
          <button
            type="submit"
            disabled={saving}
            className="flex items-center gap-2 px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 transition-colors"
          >
            <Save size={18} />
            {saving ? 'Enregistrement...' : 'Enregistrer les modifications'}
          </button>
        </div>
      </form>
    </div>
  );
}
