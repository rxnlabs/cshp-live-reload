"use strict";

( ( cshp_live_reload ) => {
    if ( typeof cshp_live_reload !== 'object' 
        || Array.isArray( cshp_live_reload ) 
        || cshp_live_reload === null
        ) {
        return;
    }

    /**
     * Get the difference between two objects and return the keys that are different as an array. Uses LoDash.
     * 
     * @see https://gist.github.com/alowdon/b8ffdf351735bb9c577ae4b9afa973f8
     * @see https://javascript.plainenglish.io/how-to-get-the-difference-between-two-javascript-objects-e885e09382cb
     */ 
    const getObjectDiff = (obj1, obj2, compareRef = false) => {
      return Object.keys(obj1).reduce((result, key) => {
        if (!obj2.hasOwnProperty(key)) {
          result.push(key);
        } else if (_.isEqual(obj1[key], obj2[key])) {
          const resultKeyIndex = result.indexOf(key);

          if (compareRef && obj1[key] !== obj2[key]) {
            result[resultKeyIndex] = `${key} (ref)`;
          } else {
            result.splice(resultKeyIndex, 1);
          }
        }
        return result;
      }, Object.keys(obj2));
    }

    const getRandomInt = (max) => {
        return Math.floor(Math.random() * max);
    }

    let original_hash = cshp_live_reload.reload_hash;
    let css_hash = cshp_live_reload.css_hash;
    let js_hash = cshp_live_reload.js_hash;
    // use an EventSource for long polling instead of using AJAX polling using an interval.
    const sse = new EventSource( cshp_live_reload.endpoint );
    const stylesheets = document.querySelectorAll( "link[rel='stylesheet']" );
    const js_scriptfiles = document.querySelectorAll( "script[src]" );

    /*
     * This will listen only for events
     * similar to the following:
     *
     * event: notice
     * data: useful data
     * id: someid
     */
    sse.addEventListener("notice", (e) => {
        console.log(e.data);
    });

    /*
     * Similarly, this will listen for events
     * with the field `event: update`
     */
    sse.addEventListener("update", (e) => {
        console.log(e.data);
    });

    /*
     * The event "message" is a special case, as it
     * will capture events without an event field
     * as well as events that have the specific type
     * `event: message` It will not trigger on any
     * other event type.
     */
    sse.addEventListener("message", (e) => {
        try {
            let json = JSON.parse(e.data);
            let reload_hash = json.reload_hash;
            let reload_css_hash = json.css_hash;
            let reload_js_hash = json.js_hash;

            //console.log( `js_hash:` );
            //console.log(js_hash);
            //console.log( `reload_js_hash}` );
            //console.log(reload_js_hash);
            if ( reload_hash !== original_hash && window.stop ) {
                // stop further resource loading, the equivalent of exit()
                window.stop();
                window.location.reload();
            }

            // reload the page when a script is changed and that script is included on this page
            if ( reload_js_hash && getObjectDiff( js_hash,reload_js_hash ).length && window.stop ) {
                let js_diff_files = getObjectDiff( js_hash,reload_js_hash );
                for ( let i = 0; i < js_scriptfiles.length; i++ ) {

                    js_diff_files.some( ( diff_js_scriptfile_url, j ) => {
                        // make sure that the js script file is included on this page already before reloading the page
                        if ( js_scriptfiles[i].src.includes( diff_js_scriptfile_url ) ) {
                            window.stop();
                            window.location.reload();
                        }

                    } );
                }
            }

            // reload the stylesheets that are loaded on this page
            if ( reload_css_hash && getObjectDiff( css_hash,reload_css_hash ).length ) {
                let css_diff_files = getObjectDiff( css_hash,reload_css_hash );
                for ( let i = 0; i < stylesheets.length; i++ ) {
                    if ( 0 == css_diff_files.length ) {
                        // if we have reloaded all the stylesheets, then stop the loop
                        break;
                    }

                    css_diff_files.some( ( diff_stylesheet_url, j ) => {
                        // make sure that the stylesheet is included on this page already before reloading it
                        if ( stylesheets[i].href.includes( diff_stylesheet_url ) ) {
                            if ( stylesheets[i].href.includes( '?' ) ) {
                                stylesheets[i].href = stylesheets[i].href + getRandomInt( 100 );
                            } else {
                                stylesheets[i].href = stylesheets[i].href + '?' + getRandomInt( 100 );
                            }

                            // remove the stylesheet that was just reloaded from the array, so we don't loop over it again
                            css_diff_files = css_diff_files.filter( ( element ) => {
                                return element !== diff_stylesheet_url;
                            } );

                            return true;
                        }

                    } );
                }

                // reset the old css hash as the new css hash
                css_hash = reload_css_hash;
            }
        } catch (e) {
            console.error(e);
        }
    });

    sse.addEventListener("error", (e) => {
        console.log(e);
        sse.close();
    });
} )( cshp_live_reload );