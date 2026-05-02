const codeEl   = document.getElementById("code")
const lineNums = document.getElementById("lineNumbers")

function actualizarLineas() {
    const n = codeEl.value.split("\n").length
    let nums = ""
    for (let i = 1; i <= n; i++) nums += i + "\n"
    lineNums.textContent = nums
}

function syncScroll() {
    lineNums.scrollTop = codeEl.scrollTop
}

function handleTab(e) {
    if (e.key !== "Tab") return
    e.preventDefault()
    const s = codeEl.selectionStart, en = codeEl.selectionEnd
    codeEl.value = codeEl.value.substring(0, s) + "    " + codeEl.value.substring(en)
    codeEl.selectionStart = codeEl.selectionEnd = s + 4
    actualizarLineas()
}

function nuevoArchivo() {
    if (codeEl.value.trim() !== "" && !confirm("¿Limpiar el editor?")) return
    codeEl.value = ""
    actualizarLineas()
}

function cargarArchivo(e) {
    const f = e.target.files[0]
    if (!f) return
    const r = new FileReader()
    r.onload = ev => { codeEl.value = ev.target.result; actualizarLineas() }
    r.readAsText(f)
    e.target.value = ""
}

function guardarArchivo() {
    const a = document.createElement("a")
    a.href = URL.createObjectURL(new Blob([codeEl.value], { type: "text/plain" }))
    a.download = "programa.golampi"
    a.click()
}

function limpiarConsola() {
    document.querySelector(".console-out").textContent = ""
}

function switchTab(nombre) {
    document.querySelectorAll(".tab").forEach(t => t.classList.remove("active"))
    document.querySelectorAll(".tab-panel").forEach(p => p.classList.remove("active"))
    document.getElementById("tab" + nombre).classList.add("active")
    document.getElementById("panel" + nombre).classList.add("active")
}

actualizarLineas()