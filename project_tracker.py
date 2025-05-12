import tkinter as tk
from tkinter import ttk, messagebox
import json
import os
from datetime import datetime
import webbrowser
import uuid

class ProjectTracker:
    def __init__(self, root):
        self.root = root
        self.root.title("GTD Project Tracker")
        self.root.geometry("1200x800")
        
        # Initialize data structures
        self.areas = []
        self.projects = []
        self.tasks = []
        
        # Load existing data
        self.load_data()
        
        self.create_widgets()
        
    def create_widgets(self):
        # Create main frames
        self.top_frame = ttk.Frame(self.root, padding="10")
        self.top_frame.pack(fill=tk.X)
        
        self.bottom_frame = ttk.Frame(self.root, padding="10")
        self.bottom_frame.pack(fill=tk.BOTH, expand=True)
        
        # Project name entry
        ttk.Label(self.top_frame, text="Project Name:").grid(row=0, column=0, padx=5, pady=5)
        self.project_name = ttk.Entry(self.top_frame)
        self.project_name.grid(row=0, column=1, padx=5, pady=5)
        
        # Status selection
        ttk.Label(self.top_frame, text="Status:").grid(row=0, column=2, padx=5, pady=5)
        self.status_var = tk.StringVar(value="In Progress")
        self.status_combo = ttk.Combobox(self.top_frame, textvariable=self.status_var, 
                                        values=["In Progress", "Waiting", "Next Action", "Someday/Maybe", "Completed"])
        self.status_combo.grid(row=0, column=3, padx=5, pady=5)
        
        # Context selection
        ttk.Label(self.top_frame, text="Context:").grid(row=0, column=4, padx=5, pady=5)
        self.context_var = tk.StringVar(value="@work")
        self.context_combo = ttk.Combobox(self.top_frame, textvariable=self.context_var, 
                                        values=["@work", "@home", "@errands", "@phone", "@computer"])
        self.context_combo.grid(row=0, column=5, padx=5, pady=5)
        
        # Add project button
        self.add_button = ttk.Button(self.top_frame, text="Add Project", command=self.add_project)
        self.add_button.grid(row=0, column=6, padx=5, pady=5)
        
        # Project list
        # Create treeview with hierarchical structure
        self.tree = ttk.Treeview(self.bottom_frame, columns=("Name", "Status", "Next Action", "Context", "Due Date", "Last Updated"), show="headings")
        
        # Set up columns
        self.tree.heading("Name", text="Name")
        self.tree.heading("Status", text="Status")
        self.tree.heading("Next Action", text="Next Action")
        self.tree.heading("Context", text="Context")
        self.tree.heading("Due Date", text="Due Date")
        self.tree.heading("Last Updated", text="Last Updated")
        
        # Configure column widths
        self.tree.column("Name", width=300)
        self.tree.column("Status", width=100)
        self.tree.column("Next Action", width=200)
        self.tree.column("Context", width=100)
        self.tree.column("Due Date", width=100)
        self.tree.column("Last Updated", width=150)
        
        self.tree.pack(fill=tk.BOTH, expand=True)
        
        # Context menu
        self.tree.bind("<Button-3>", self.show_context_menu)
        
        # Update tree
        self.update_tree()
        
    def add_area(self):
        area_name = self.area_name.get().strip()
        if not area_name:
            messagebox.showwarning("Warning", "Please enter an area name")
            return
            
        self.areas.append({
            "id": str(uuid.uuid4()),
            "name": area_name,
            "projects": []
        })
        
        self.save_data()
        self.update_tree()
        self.area_name.delete(0, tk.END)

    def add_project(self, area_id=None):
        project_name = self.project_name.get().strip()
        if not project_name:
            messagebox.showwarning("Warning", "Please enter a project name")
            return
            
        project = {
            "id": str(uuid.uuid4()),
            "name": project_name,
            "status": self.status_var.get(),
            "context": self.context_var.get(),
            "next_action": "",
            "notes": "",
            "due_date": "",
            "last_updated": datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        }
        
        if area_id:
            # Add to specific area
            for area in self.areas:
                if area["id"] == area_id:
                    area["projects"].append(project)
                    break
        else:
            # Add as standalone project
            self.projects.append(project)
        
        self.save_data()
        self.update_tree()
        self.project_name.delete(0, tk.END)
        
        # Open edit dialog immediately after adding
        self.edit_project_dialog(project)
        
    def update_tree(self):
        # Clear existing items
        for item in self.tree.get_children():
            self.tree.delete(item)
        
        # Add areas
        for area in self.areas:
            area_id = self.tree.insert("", "end", text=area["name"], values=(area["name"], "Area", "", "", "", ""))
            
            # Add projects under the area
            for project in area["projects"]:
                self.tree.insert(area_id, "end", text=project["name"], values=(
                    project["name"],
                    project["status"],
                    project["next_action"],
                    project["context"],
                    project["due_date"],
                    project["last_updated"]
                ))
        
        # Add standalone projects
        for project in self.projects:
            self.tree.insert("", "end", text=project["name"], values=(
                project["name"],
                project["status"],
                project["next_action"],
                project["context"],
                project["due_date"],
                project["last_updated"]
            ))
    
    def open_notes(self, item):
        item_text = self.tree.item(item, "text")
        area = next((a for a in self.areas if a["name"] == item_text), None)
        
        if area:
            # It's an area, not a project
            return
            
        # Find the project
        project = None
        for area in self.areas:
            for p in area["projects"]:
                if p["name"] == item_text:
                    project = p
                    break
            if project:
                break
        
        if not project:
            project = next((p for p in self.projects if p["name"] == item_text), None)
        
        if project and project["notes"]:
            notes_window = tk.Toplevel(self.root)
            notes_window.title(f"Notes for {item_text}")
            
            text_widget = tk.Text(notes_window, height=10, width=50)
            text_widget.insert(tk.END, project["notes"])
            text_widget.pack(padx=10, pady=10)
            
            ttk.Button(notes_window, text="Close", command=notes_window.destroy).pack(pady=5)
            
    def show_context_menu(self, event):
        item = self.tree.identify_row(event.y)
        if item:
            self.tree.selection_set(item)
            self.tree.focus(item)
            
            item_text = self.tree.item(item, "text")
            menu = tk.Menu(self.root, tearoff=0)
            
            # Different menu options based on whether it's an area or project
            if item_text in [area["name"] for area in self.areas]:
                menu.add_command(label="Edit Area", command=lambda: self.edit_area(item))
                menu.add_command(label="Delete Area", command=lambda: self.delete_area(item))
                menu.add_command(label="Add Project to Area", command=lambda: self.add_project(item))
            else:
                menu.add_command(label="Edit Project", command=lambda: self.edit_project(item))
                menu.add_command(label="Add Task", command=lambda: self.add_task(item))
                menu.add_command(label="Mark as Complete", command=lambda: self.mark_complete(item))
                menu.add_command(label="Delete Project", command=lambda: self.delete_project(item))
                menu.add_command(label="Open Notes", command=lambda: self.open_notes(item))
            
            menu.post(event.x_root, event.y_root)
    
    def edit_area(self, item):
        area_name = self.tree.item(item, "text")
        area = next((a for a in self.areas if a["name"] == area_name), None)
        
        if area:
            dialog = tk.Toplevel(self.root)
            dialog.title("Edit Area")
            
            ttk.Label(dialog, text="Area Name:").grid(row=0, column=0, padx=5, pady=5)
            name_entry = ttk.Entry(dialog)
            name_entry.insert(0, area["name"])
            name_entry.grid(row=0, column=1, padx=5, pady=5)
            
            ttk.Button(dialog, text="Save", command=lambda: self.save_edited_area(
                dialog, area, name_entry.get()
            )).grid(row=1, column=1, padx=5, pady=5)

    def save_edited_area(self, dialog, area, new_name):
        area["name"] = new_name
        area["last_updated"] = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        
        self.save_data()
        self.update_tree()
        dialog.destroy()

    def edit_project(self, item):
        item_text = self.tree.item(item, "text")
        area = next((a for a in self.areas if a["name"] == item_text), None)
        
        if area:
            # It's an area, not a project
            return
            
        # Find the project
        project = None
        for area in self.areas:
            for p in area["projects"]:
                if p["name"] == item_text:
                    project = p
                    break
            if project:
                break
        
        if not project:
            project = next((p for p in self.projects if p["name"] == item_text), None)
        
        if project:
            dialog = tk.Toplevel(self.root)
            dialog.title("Edit Project")
            
            ttk.Label(dialog, text="Project Name:").grid(row=0, column=0, padx=5, pady=5)
            name_entry = ttk.Entry(dialog)
            name_entry.insert(0, project["name"])
            name_entry.grid(row=0, column=1, padx=5, pady=5)
            
            ttk.Label(dialog, text="Status:").grid(row=1, column=0, padx=5, pady=5)
            status_var = tk.StringVar(value=project["status"])
            status_combo = ttk.Combobox(dialog, textvariable=status_var, 
                                      values=["In Progress", "Waiting", "Next Action", "Someday/Maybe", "Completed"])
            status_combo.grid(row=1, column=1, padx=5, pady=5)
            
            ttk.Label(dialog, text="Context:").grid(row=2, column=0, padx=5, pady=5)
            context_var = tk.StringVar(value=project["context"])
            context_combo = ttk.Combobox(dialog, textvariable=context_var, 
                                       values=["@work", "@home", "@errands", "@phone", "@computer"])
            context_combo.grid(row=2, column=1, padx=5, pady=5)
            
            ttk.Label(dialog, text="Next Action:").grid(row=3, column=0, padx=5, pady=5)
            next_action_entry = ttk.Entry(dialog)
            next_action_entry.insert(0, project["next_action"])
            next_action_entry.grid(row=3, column=1, padx=5, pady=5)
            
            ttk.Label(dialog, text="Due Date:").grid(row=4, column=0, padx=5, pady=5)
            due_date_entry = ttk.Entry(dialog)
            due_date_entry.insert(0, project["due_date"])
            due_date_entry.grid(row=4, column=1, padx=5, pady=5)
            
            ttk.Label(dialog, text="Notes:").grid(row=5, column=0, padx=5, pady=5)
            notes_text = tk.Text(dialog, height=5)
            notes_text.insert(tk.END, project["notes"])
            notes_text.grid(row=5, column=1, padx=5, pady=5)
            
            ttk.Button(dialog, text="Save", command=lambda: self.save_edited_project(
                dialog, project, name_entry.get(), status_var.get(), 
                context_var.get(), next_action_entry.get(), notes_text.get("1.0", tk.END)
            )).grid(row=6, column=1, padx=5, pady=5)

    def save_edited_project(self, dialog, project, name, status, context, next_action, notes):
        project["name"] = name
        project["status"] = status
        project["context"] = context
        project["next_action"] = next_action
        project["notes"] = notes.strip()
        project["last_updated"] = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
        
        self.save_data()
        self.update_tree()
        dialog.destroy()

    def delete_project(self, item):
        item_text = self.tree.item(item, "text")
        area = next((a for a in self.areas if a["name"] == item_text), None)
        
        if area:
            # It's an area, not a project
            return
            
        # Find the project
        project = None
        for area in self.areas:
            for p in area["projects"]:
                if p["name"] == item_text:
                    project = p
                    break
            if project:
                break
        
        if not project:
            project = next((p for p in self.projects if p["name"] == item_text), None)
        
        if project and messagebox.askyesno("Confirm Delete", f"Are you sure you want to delete project '{item_text}'?"):
            # Remove from area if it exists in one
            for area in self.areas:
                area["projects"] = [p for p in area["projects"] if p["name"] != item_text]
            
            # Remove from standalone projects
            self.projects = [p for p in self.projects if p["name"] != item_text]
            
            self.save_data()
            self.update_tree()
                
    def save_data(self):
        with open("gtd_data.json", "w") as f:
            json.dump({
                "areas": self.areas,
                "projects": self.projects,
                "tasks": self.tasks
            }, f, indent=4)
            
    def load_data(self):
        if os.path.exists("gtd_data.json"):
            with open("gtd_data.json", "r") as f:
                data = json.load(f)
                self.areas = data.get("areas", [])
                self.projects = data.get("projects", [])
                self.tasks = data.get("tasks", [])
                
if __name__ == "__main__":
    root = tk.Tk()
    app = ProjectTracker(root)
    root.mainloop()
