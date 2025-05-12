import sqlite3
from datetime import datetime
import tkinter as tk
from tkinter import ttk, messagebox

class TodoApp:
    def __init__(self, root):
        self.root = root
        self.root.title("Todo App")
        self.root.geometry("600x400")
        
        # Create database connection
        self.conn = self.create_connection()
        if self.conn:
            self.create_table()
            self.setup_ui()
        else:
            messagebox.showerror("Error", "Failed to connect to database")
            self.root.destroy()

    def create_connection(self):
        """Create a database connection to SQLite database"""
        try:
            conn = sqlite3.connect('todo.db')
            return conn
        except sqlite3.Error as e:
            print(f"Error connecting to database: {e}")
            return None

    def create_table(self):
        """Create table for storing todo items"""
        try:
            cursor = self.conn.cursor()
            cursor.execute('''
                CREATE TABLE IF NOT EXISTS todos (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    task TEXT NOT NULL,
                    completed BOOLEAN DEFAULT FALSE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )
            ''')
            self.conn.commit()
        except sqlite3.Error as e:
            print(f"Error creating table: {e}")

    def setup_ui(self):
        # Create main frame
        main_frame = ttk.Frame(self.root, padding="10")
        main_frame.grid(row=0, column=0, sticky=(tk.W, tk.E, tk.N, tk.S))

        # Task entry
        self.task_entry = ttk.Entry(main_frame, width=50)
        self.task_entry.grid(row=0, column=0, padx=5, pady=5)

        # Add button
        add_button = ttk.Button(main_frame, text="Add Task", command=self.add_task)
        add_button.grid(row=0, column=1, padx=5, pady=5)

        # Treeview for displaying tasks
        columns = ('ID', 'Task', 'Status', 'Created')
        self.tree = ttk.Treeview(main_frame, columns=columns, show='headings')
        
        for col in columns:
            self.tree.heading(col, text=col)
            self.tree.column(col, width=100)

        self.tree.grid(row=1, column=0, columnspan=2, padx=5, pady=5)

        # Refresh button
        refresh_button = ttk.Button(main_frame, text="Refresh", command=self.show_tasks)
        refresh_button.grid(row=2, column=0, padx=5, pady=5)

        # Mark complete button
        complete_button = ttk.Button(main_frame, text="Mark Complete", command=self.mark_complete)
        complete_button.grid(row=2, column=1, padx=5, pady=5)

        # Initial task display
        self.show_tasks()

    def add_task(self):
        """Add a new task to the database"""
        task = self.task_entry.get().strip()
        if task:
            try:
                cursor = self.conn.cursor()
                cursor.execute('INSERT INTO todos (task) VALUES (?)', (task,))
                self.conn.commit()
                self.task_entry.delete(0, tk.END)
                self.show_tasks()
            except sqlite3.Error as e:
                messagebox.showerror("Error", f"Error adding task: {e}")
        else:
            messagebox.showwarning("Warning", "Task cannot be empty!")

    def show_tasks(self):
        """Show all tasks in the treeview"""
        # Clear existing items
        for item in self.tree.get_children():
            self.tree.delete(item)

        try:
            cursor = self.conn.cursor()
            cursor.execute('SELECT id, task, completed, created_at FROM todos')
            tasks = cursor.fetchall()

            for task in tasks:
                status = "✓" if task[2] else " "
                self.tree.insert("", "end", values=(task[0], task[1], status, task[3]))
        except sqlite3.Error as e:
            messagebox.showerror("Error", f"Error showing tasks: {e}")

    def mark_complete(self):
        """Mark selected task as complete"""
        selected_item = self.tree.selection()
        if selected_item:
            item = self.tree.item(selected_item[0])
            task_id = int(item['values'][0])
            
            try:
                cursor = self.conn.cursor()
                cursor.execute('UPDATE todos SET completed = TRUE WHERE id = ?', (task_id,))
                self.conn.commit()
                self.show_tasks()
            except sqlite3.Error as e:
                messagebox.showerror("Error", f"Error marking task as complete: {e}")
        else:
            messagebox.showwarning("Warning", "Please select a task first!")

    def __del__(self):
        """Close database connection when app is closed"""
        if hasattr(self, 'conn'):
            self.conn.close()

if __name__ == '__main__':
    root = tk.Tk()
    app = TodoApp(root)
    root.mainloop()
