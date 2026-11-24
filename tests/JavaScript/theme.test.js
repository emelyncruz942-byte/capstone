import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source=readFileSync(new URL('../../public/js/theme.js',import.meta.url),'utf8');
function fixture(saved=null, denied=false, locked=false){
    const listeners=new Map(), windowListeners=new Map(), savedWrites=[], events=[];
    const label={textContent:''}, icon={className:''}, attributes={};
    const button={setAttribute:(name,value)=>attributes[name]=value,querySelector:selector=>selector==='[data-theme-label]'?label:icon};
    const root={dataset:locked?{themeLock:'dark'}:{},style:{}};
    const metadata={};
    const context=vm.createContext({
        document:{documentElement:root,
            querySelectorAll:()=>[button],
            querySelector:selector=>({setAttribute:(name,value)=>metadata[selector]=value}),
            addEventListener:(name,handler)=>listeners.set(name,handler),
            dispatchEvent:event=>events.push(event)},
        window:{localStorage:{getItem:()=>{if(denied)throw new Error('Blocked');return saved;},setItem:(key,value)=>{if(denied)throw new Error('Blocked');savedWrites.push([key,value]);}},addEventListener:(name,handler)=>windowListeners.set(name,handler)},
        CustomEvent:class{constructor(type,init){this.type=type;this.detail=init.detail;}}
    });
    vm.runInContext(source,context);
    return {context,root,attributes,label,icon,metadata,events,savedWrites,listeners,windowListeners,
        click(){listeners.get('click')({target:{closest:()=>button},preventDefault(){}});}};
}
test('the original dark game theme is the default before styles load',()=>{
    const f=fixture();assert.equal(f.root.dataset.theme,'dark');assert.equal(f.root.style.colorScheme,'dark');assert.equal(f.savedWrites.length,0);
    assert.equal(f.label.textContent,'Light mode');assert.equal(f.attributes['aria-pressed'],'false');
});
test('a saved light theme is applied immediately and native controls match it',()=>{
    const f=fixture('light');assert.equal(f.root.dataset.theme,'light');assert.equal(f.root.style.colorScheme,'light');
    assert.equal(f.attributes['aria-label'],'Switch to dark mode');assert.equal(f.label.textContent,'Dark mode');
    assert.equal(f.metadata['meta[name="theme-color"]'],'#edf5fb');
});
test('the sign-in page remains dark without overwriting a saved preference',()=>{
    const f=fixture('light',false,true);
    assert.equal(f.root.dataset.theme,'dark');
    assert.equal(f.root.style.colorScheme,'dark');
    f.context.window.MathVerseTheme.set('light');
    assert.equal(f.root.dataset.theme,'dark');
    assert.deepEqual(f.savedWrites,[]);
    f.windowListeners.get('storage')({key:'mathverse.theme',newValue:'light'});
    assert.equal(f.root.dataset.theme,'dark');
});
test('the visible switch toggles both ways and stores only the theme preference',()=>{
    const f=fixture();f.click();assert.equal(f.root.dataset.theme,'light');assert.equal(f.attributes['aria-pressed'],'true');
    assert.equal(f.events.at(-1).detail.theme,'light');f.click();assert.equal(f.root.dataset.theme,'dark');
    assert.deepEqual(f.savedWrites,[['mathverse.theme','light'],['mathverse.theme','dark']]);
});
test('disabled preference storage never breaks switching',()=>{
    const f=fixture(null,true);assert.doesNotThrow(()=>f.click());assert.equal(f.root.dataset.theme,'light');
});
test('an unknown saved preference safely restores dark',()=>{
    const f=fixture('unexpected');assert.equal(f.root.dataset.theme,'dark');assert.equal(f.root.style.colorScheme,'dark');
});
test('tab synchronization updates the theme without rewriting preferences',()=>{
    const f=fixture();f.windowListeners.get('storage')({key:'mathverse.theme',newValue:'light'});
    assert.equal(f.root.dataset.theme,'light');assert.equal(f.savedWrites.length,0);
    f.windowListeners.get('storage')({key:'unrelated',newValue:'dark'});assert.equal(f.root.dataset.theme,'light');
});
test('page readiness and dashboard navigation retain the chosen theme',()=>{
    const f=fixture();f.click();f.listeners.get('DOMContentLoaded')();f.listeners.get('mathverse:page-ready')();f.listeners.get('mathverse:page-loaded')();f.windowListeners.get('pageshow')();
    assert.equal(f.root.dataset.theme,'light');assert.equal(f.label.textContent,'Dark mode');assert.equal(f.savedWrites.length,1);
});
