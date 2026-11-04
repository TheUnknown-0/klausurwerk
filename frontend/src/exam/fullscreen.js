/**
 * Vollbild-Helfer (mit webkit-Präfix als Rückfall). Muss aus einer Nutzergeste
 * heraus aufgerufen werden.
 */
export const fullscreenSupported = () => !!(document.documentElement.requestFullscreen || document.documentElement.webkitRequestFullscreen);

export const isFullscreen = () => !!(document.fullscreenElement || document.webkitFullscreenElement);

/** true, wenn der Vollbildmodus aktiv ist. */
export async function enterFullscreen() {
    if (isFullscreen()) {
        return true;
    }
    const el = document.documentElement;
    try {
        if (el.requestFullscreen) {
            await Promise.race([el.requestFullscreen({ navigationUI: 'hide' }), new Promise((r) => setTimeout(r, 3000))]);
        } else if (el.webkitRequestFullscreen) {
            el.webkitRequestFullscreen();
        } else {
            return false;
        }
    } catch {
        return false;
    }
    return isFullscreen();
}

export async function exitFullscreen() {
    try {
        if (document.fullscreenElement && document.exitFullscreen) {
            await document.exitFullscreen();
        } else if (document.webkitFullscreenElement && document.webkitExitFullscreen) {
            document.webkitExitFullscreen();
        }
    } catch {
        // egal
    }
}
