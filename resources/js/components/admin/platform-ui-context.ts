import { createContext, useContext } from 'react';

// React context also scopes portalled dialogs/drawers without affecting tenant UI.
export const PlatformUiContext = createContext(false);
export const usePlatformUi = () => useContext(PlatformUiContext);
