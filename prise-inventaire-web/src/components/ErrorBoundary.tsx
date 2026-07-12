import { Component, type ReactNode } from 'react';

interface Props {
  children: ReactNode;
}

interface State {
  hasError: boolean;
}

/**
 * Capture les erreurs de rendu React et affiche un écran clair au lieu d'une
 * page blanche (contexte connectivité/instabilité : on ne laisse jamais
 * l'utilisateur devant un écran vide sans explication).
 */
export default class ErrorBoundary extends Component<Props, State> {
  state: State = { hasError: false };

  static getDerivedStateFromError(): State {
    return { hasError: true };
  }

  componentDidCatch(error: unknown, info: unknown): void {
    console.error('Erreur de rendu capturée par ErrorBoundary:', error, info);
  }

  private handleReload = (): void => {
    window.location.reload();
  };

  render(): ReactNode {
    if (this.state.hasError) {
      return (
        <div className="min-h-screen flex items-center justify-center bg-gray-50 dark:bg-gray-900 p-6">
          <div className="max-w-md text-center">
            <h1 className="text-xl font-bold text-gray-800 dark:text-white mb-2">
              Une erreur est survenue
            </h1>
            <p className="text-gray-500 dark:text-gray-400 mb-4">
              L'application a rencontré un problème. Rechargez la page ; si cela persiste,
              contactez le support.
            </p>
            <button
              onClick={this.handleReload}
              className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
            >
              Recharger
            </button>
          </div>
        </div>
      );
    }

    return this.props.children;
  }
}
