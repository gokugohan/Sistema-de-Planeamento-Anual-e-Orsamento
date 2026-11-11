{{-- The drawing of the organization chart, shared by the page and its print. --}}
<style>
    /* edges by kind of unit */
    .oc-a-cab { --oc-edge: #7A5AF8; } .oc-a-dg { --oc-edge: #465FFF; } .oc-a-dn { --oc-edge: #0BA5EC; }
    .oc-a-dep { --oc-edge: #12B76A; } .oc-a-reg { --oc-edge: #F79009; } .oc-a-unit { --oc-edge: #98A2B3; } .oc-a-body { --oc-edge: #F79009; }
    .oc-swatch { display: inline-block; width: .9rem; height: .55rem; border-radius: 2px; background: var(--oc-edge); }
    .oc-swatch-dashed { background: transparent; border: 1.5px dashed var(--oc-edge); }

    .oc { --oc-line: #98A2B3; width: max-content; }
    .oc ul { position: relative; display: flex; justify-content: center; padding-top: 28px; margin: 0; }
    .oc li { position: relative; list-style: none; padding: 28px 10px 0; display: flex; flex-direction: column; align-items: center; }
    /* the bar across the siblings and the drop to each of them */
    .oc li::before, .oc li::after { content: ""; position: absolute; top: 0; right: 50%; width: 50%; height: 28px; border-top: 2px solid var(--oc-line); }
    .oc li::after { right: auto; left: 50%; border-left: 2px solid var(--oc-line); }
    .oc li:only-child::before, .oc li:only-child::after { display: none; }
    .oc li:only-child { padding-top: 0; }
    .oc li:first-child::before, .oc li:last-child::after { border: 0 none; }
    .oc li:last-child::before { border-right: 2px solid var(--oc-line); border-top-right-radius: 6px; }
    .oc li:first-child::after { border-top-left-radius: 6px; }
    /* the drop from a parent to the bar of its children */
    .oc ul ul::before { content: ""; position: absolute; top: 0; left: 50%; height: 28px; border-left: 2px solid var(--oc-line); }
    .oc > ul { padding-top: 0; }
    .oc > ul > li { padding-top: 0; }
    .oc > ul > li::before, .oc > ul > li::after { display: none; }
    /* supervised bodies hang from a dashed line */
    .oc li.oc-supervised::after { border-left-style: dashed; }

    /* below the first level the units hang in a column on the left of their parent */
    .oc ul.oc-col { display: block; align-self: stretch; margin: 10px 0 0 22px; padding: 0; }
    .oc ul.oc-col::before { display: none; }
    .oc ul.oc-col > li { display: block; padding: 8px 0 0 18px; }
    .oc ul.oc-col > li::before { top: 0; left: 0; right: auto; width: 18px; height: 34px; border: 0; border-left: 2px solid var(--oc-line); border-bottom: 2px solid var(--oc-line); border-radius: 0 0 0 6px; display: block; }
    .oc ul.oc-col > li::after { top: 34px; bottom: 0; left: 0; width: 0; height: auto; border: 0; border-left: 2px solid var(--oc-line); border-radius: 0; display: block; }
    .oc ul.oc-col > li:last-child::after { display: none; }
    .oc ul.oc-col > li:only-child { padding-top: 8px; }
    .oc ul.oc-col ul.oc-col { margin-left: 18px; }

    /* the staff block on the trunk: the line runs through it from the institution to the row of directorates */
    .oc-staff { position: relative; margin-top: 28px; padding: 26px 18px 14px; border: 1px dashed var(--c-neutral-300, #D0D5DD); border-radius: .9rem; }
    .oc-staff::before { content: ""; position: absolute; left: 50%; top: -28px; height: 28px; border-left: 2px solid var(--oc-line); }
    .oc-staff::after { content: ""; position: absolute; left: 50%; bottom: -28px; height: 28px; border-left: 2px solid var(--oc-line); }
    .oc-staff-label { position: absolute; top: 6px; left: 0; right: 0; text-align: center; font-size: 10px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: #667085; }
    .oc-staff-grid { display: grid; grid-template-columns: repeat(var(--oc-cols, 4), 15.5rem); gap: 12px 16px; justify-content: center; }

    .oc-top { min-width: 18rem; max-width: 24rem; padding: 1rem 1.5rem; border-radius: .75rem; background: #161950; text-align: center; box-shadow: 0 10px 25px -10px rgba(22, 25, 80, .55); }
    .oc-box { width: 15.5rem; text-align: left; border-radius: .6rem; border: 1px solid var(--c-neutral-200, #E4E7EC); border-top: 4px solid var(--oc-edge); background: var(--surface, #fff); box-shadow: 0 1px 2px rgba(16, 24, 40, .06); transition: box-shadow .15s, transform .15s; }
    .oc-box:hover { box-shadow: 0 8px 20px -8px rgba(16, 24, 40, .25); transform: translateY(-1px); }
    .oc-box.oc-dashed { border-style: dashed; border-top-style: solid; }
    .oc-box.oc-hit { outline: 3px solid #FDB022; outline-offset: 2px; }
    .oc-box.oc-current { outline-color: #F04438; }
    .oc-avatar { background: color-mix(in srgb, var(--oc-edge) 14%, transparent); color: var(--oc-edge); }
</style>
