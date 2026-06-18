// Tracks rows with unconfirmed edits so navigation can warn before discarding
// them (wireframe A12 / UC-AD30). Imperative + app-global by nature, so a tiny
// module singleton beats threading state through every component.
// ponytail: module singleton; promote to context only if a screen needs to react.

const dirty = new Set();
let bound = false;

const onBeforeUnload = ( e ) => {
	e.preventDefault();
	e.returnValue = '';
};

const sync = () => {
	const need = dirty.size > 0;
	if ( need && ! bound ) {
		window.addEventListener( 'beforeunload', onBeforeUnload );
		bound = true;
	} else if ( ! need && bound ) {
		window.removeEventListener( 'beforeunload', onBeforeUnload );
		bound = false;
	}
};

/** Mark/unmark one row (by source key) as having an unsaved edit. */
export const setDirty = ( key, isDirty ) => {
	if ( isDirty ) {
		dirty.add( key );
	} else {
		dirty.delete( key );
	}
	sync();
};

/**
 * Gate an in-app navigation (tab/language/area/pagination switch). Returns true
 * when it's safe to proceed; on confirm it clears the pending edits, since the
 * destination view reloads and the unsaved fields are discarded anyway.
 */
export const confirmDiscard = () => {
	if ( dirty.size === 0 ) {
		return true;
	}
	// eslint-disable-next-line no-alert
	if ( window.confirm( 'You have unsaved translations. Leave without saving them?' ) ) {
		dirty.clear();
		sync();
		return true;
	}
	return false;
};
